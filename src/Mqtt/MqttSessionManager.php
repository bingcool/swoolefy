<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoole\Server;

/**
 * Worker 内 MQTT 会话与订阅注册中心（Broker 核心状态机）。
 *
 * ## 职责
 * - 维护 fd ↔ client_id 映射；同 client_id 重连时踢掉旧 fd
 * - 记录每个 fd 的 topic filter 订阅（供 publish 路由）
 * - 内存 Retain 消息表（生产环境大量 retain 建议换 Redis/DB）
 * - QoS2 入站报文暂存（PUBLISH → PUBREC，待 PUBREL 释放后 dispatch）
 * - Broker→Client 出站 QoS1/2 待确认（优雅停机 drain 用）
 *
 * ## 会话 / 重连语义（当前实现）
 * - 状态均为 **Worker 内存**，断连即 `remove()`，**无跨断连持久会话**
 * - 同 client_id 新连接会踢掉旧 fd（`bind`）
 * - `clean_session=0` 仅保留协议字段；CONNACK `session_present` 恒为 false（无恢复可声明）
 * - 异常断连 Will 发送尚未实现（retain will 在 CONNECT 时写入 Retain 表）
 *
 * ## 多 Worker
 * fd 仅在本 Worker 有效。Swoole 建议 `dispatch_mode=2` 保证同一 TCP 连接固定 Worker。
 * 跨 Worker 广播需扩展 pipeMessage / Redis pubsub（见 README）。
 *
 * ## 单例
 * 每个 Worker 进程一个实例；Worker 热重启时由 MqttServer::workerStartInit 调用 reset()。
 */
final class MqttSessionManager
{
    private static ?self $instance = null;

    /** @var array<int, MqttSession> */
    private array $sessions = [];

    /** @var array<string, int> client_id => fd */
    private array $clientIndex = [];

    /** @var array<string, array{message:string,qos:int,retain:bool,timestamp:int}> */
    private array $retainedMessages = [];

    /**
     * Broker→Client 出站待确认：fd => [message_id => qos]
     *
     * @var array<int, array<int, int>>
     */
    private array $outboundPending = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function get(int $fd): ?MqttSession
    {
        return $this->sessions[$fd] ?? null;
    }

    public function requireConnected(int $fd): MqttSession
    {
        $session = $this->get($fd);
        // 未 bind 或 CONNECT 未完成时，SUBSCRIBE/PUBLISH 等操作应拒绝
        if ($session === null || !$session->connected) {
            throw new MqttProtocolException('MQTT session is not connected', $fd);
        }

        return $session;
    }

    /**
     * 绑定 CONNECT 成功后的会话（注册 client_id、处理 will retain、Clean Session）。
     *
     * @param array<string, mixed> $will CONNECT 报文中的 will 结构（topic/message/qos/retain）
     */
    public function bind(
        int $fd,
        string $clientId,
        string $username,
        int $keepAlive,
        int $protocolLevel,
        bool $cleanSession,
        array $will = [],
    ): MqttSession {
        // 同 client_id 重连：踢掉旧 fd，避免同一客户端占两个连接
        if ($clientId !== '' && isset($this->clientIndex[$clientId])) {
            $oldFd = $this->clientIndex[$clientId];
            if ($oldFd !== $fd) {
                $this->remove($oldFd, sendWill: false);
            }
        }

        // Clean Session=1：丢弃该 fd 上可能残留的旧会话状态
        if ($cleanSession) {
            $this->remove($fd, sendWill: false);
        }

        $session = new MqttSession(
            fd: $fd,
            clientId: $clientId,
            username: $username,
            keepAlive: $keepAlive,
            protocolLevel: $protocolLevel,
            connected: true,
            cleanSession: $cleanSession,
        );
        $this->sessions[$fd] = $session;

        // 非空 client_id 才建立反向索引，便于后续重连查找
        if ($clientId !== '') {
            $this->clientIndex[$clientId] = $fd;
        }

        // Will 消息若带 retain 标志，CONNECT 时即写入 Retain 表（非正常断连 will 发送待扩展）
        if ($will !== [] && isset($will['topic'], $will['message'])) {
            $this->storeRetained(
                (string) $will['topic'],
                (string) $will['message'],
                (int) ($will['qos'] ?? 0),
                (bool) ($will['retain'] ?? false),
            );
        }

        return $session;
    }

    /**
     * 注册订阅并返回 SUBACK granted codes。
     *
     * @param array<string, int|array<string, mixed>> $topics V3: topic=>qos；V5: topic=>['qos'=>..,'no_local'=>..]
     * @return array<int> SUBACK 授权码（失败为 0x80/0x83 等）
     */
    public function subscribe(int $fd, array $topics, int $protocolLevel): array
    {
        $session = $this->requireConnected($fd);
        $codes = [];

        foreach ($topics as $topic => $option) {
            $topic = (string) $topic;
            // 空 filter 直接拒绝（V3/V5 均返回 0x80 Unspecified error）
            if ($topic === '') {
                $codes[] = $protocolLevel === MQTT_PROTOCOL_LEVEL5 ? 0x80 : 0x80;
                continue;
            }

            // V5 订阅选项为数组；V3 仅为 qos 整数
            if ($protocolLevel === MQTT_PROTOCOL_LEVEL5 && is_array($option)) {
                $qos = (int) ($option['qos'] ?? 0);
                $noLocal = (bool) ($option['no_local'] ?? false);
            } else {
                $qos = (int) $option;
                $noLocal = false;
            }

            // 非法 QoS 拒绝：V5 用 0x83 QoS not supported，V3 用 0x80
            if ($qos < 0 || $qos > 2) {
                $codes[] = $protocolLevel === MQTT_PROTOCOL_LEVEL5 ? 0x83 : 0x80;
                continue;
            }

            // 同一 filter 重复订阅会覆盖旧选项（MQTT 允许）
            $session->subscriptions[$topic] = [
                'qos' => $qos,
                'no_local' => $noLocal,
            ];
            $codes[] = $qos;
        }

        return $codes;
    }

    /**
     * @param array<int, string>|array<string, mixed> $topics
     */
    public function unsubscribe(int $fd, array $topics): void
    {
        $session = $this->requireConnected($fd);

        foreach ($topics as $key => $value) {
            // unpack 后可能是 ['filter'=>qos] 或 [0=>'filter'] 两种结构
            $topic = is_string($key) ? $key : (string) $value;
            unset($session->subscriptions[$topic]);
        }
    }

    /**
     * 存储或清除 Retain 消息（retain=false 时删除该 topic 的 retain 条目）。
     */
    public function storeRetained(string $topic, string $message, int $qos, bool $retain): void
    {
        // retain=false 的 PUBLISH 会清除该 topic 已有 Retain（MQTT 规范）
        if (!$retain) {
            unset($this->retainedMessages[$topic]);
            return;
        }

        // 同一 topic 新 Retain 覆盖旧值
        $this->retainedMessages[$topic] = [
            'message' => $message,
            'qos' => min(max($qos, 0), 2),
            'retain' => true,
            'timestamp' => time(),
        ];
    }

    /**
     * 查找 publish topic 的所有匹配订阅者（已连接 fd + 协商 QoS + no_local 过滤）。
     *
     * @return array<int, array{fd:int,qos:int,no_local:bool}>
     */
    public function matchSubscribers(string $topic, int $publisherFd): array
    {
        $matches = [];

        foreach ($this->sessions as $fd => $session) {
            if (!$session->connected) {
                continue;
            }

            foreach ($session->subscriptions as $filter => $options) {
                if (!MqttTopicMatcher::matches($filter, $topic)) {
                    continue;
                }

                // V5 no_local：发布者自己不收自己发的消息
                if ($options['no_local'] && $fd === $publisherFd) {
                    continue;
                }

                $matches[] = [
                    'fd' => $fd,
                    'qos' => (int) $options['qos'],
                    'no_local' => (bool) $options['no_local'],
                ];
                // 同一 fd 多个 filter 命中时只取第一个（避免重复投递）
                break;
            }
        }

        return $matches;
    }

    /**
     * Retained messages visible to a new subscription filter.
     *
     * @return array<string, array{message:string,qos:int,retain:bool,timestamp:int}>
     */
    public function retainedForSubscription(string $filter): array
    {
        $result = [];
        foreach ($this->retainedMessages as $topic => $payload) {
            if (MqttTopicMatcher::matches($filter, $topic)) {
                $result[$topic] = $payload;
            }
        }

        return $result;
    }

    public function allRetainedForTopic(string $topicFilter): ?array
    {
        if (isset($this->retainedMessages[$topicFilter])) {
            return $this->retainedMessages[$topicFilter];
        }

        foreach ($this->retainedMessages as $filter => $payload) {
            if (MqttTopicMatcher::matches($filter, $topicFilter)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * QoS2：暂存客户端 PUBLISH，待收到 PUBREL 后由 Dispatcher 释放并 dispatch。
     */
    public function rememberInboundQoS2(int $fd, int|string $messageId, string $topic, string $message, int $qos, bool $retain): void
    {
        $session = $this->requireConnected($fd);
        // 以 message_id 为键暂存，PUBREL 到达后 release 并删除
        $session->inboundQoS2[(int) $messageId] = [
            'topic' => $topic,
            'message' => $message,
            'qos' => $qos,
            'retain' => $retain,
        ];
    }

    /**
     * QoS2：PUBREL 到达时取出暂存 payload（仅一次有效）。
     *
     * @return array{topic:string,message:string,qos:int,retain:bool}|null
     */
    public function releaseInboundQoS2(int $fd, int|string $messageId): ?array
    {
        $session = $this->get($fd);
        if ($session === null) {
            return null;
        }

        $id = (int) $messageId;
        if (!isset($session->inboundQoS2[$id])) {
            return null; // 重复 PUBREL 或未知 message_id
        }

        // 取出后立即删除，保证 QoS2 恰好一次语义
        $payload = $session->inboundQoS2[$id];
        unset($session->inboundQoS2[$id]);

        return $payload;
    }

    /**
     * 记录 Broker→Client 出站 QoS1/2，待客户端 PUBACK/PUBCOMP 清除。
     */
    public function rememberOutbound(int $fd, int $messageId, int $qos): void
    {
        if ($messageId <= 0 || $qos < 1) {
            return;
        }
        if ($this->get($fd) === null) {
            return;
        }

        $this->outboundPending[$fd][$messageId] = min(2, max(1, $qos));
    }

    /**
     * 客户端确认出站报文：QoS1→PUBACK；QoS2→PUBCOMP（或简化为 PUBREC 即清）。
     */
    public function ackOutbound(int $fd, int|string $messageId): void
    {
        $id = (int) $messageId;
        if ($id <= 0 || !isset($this->outboundPending[$fd][$id])) {
            return;
        }
        unset($this->outboundPending[$fd][$id]);
        if (($this->outboundPending[$fd] ?? []) === []) {
            unset($this->outboundPending[$fd]);
        }
    }

    /** 本 Worker 在途 QoS 数量（入站 QoS2 暂存 + 出站待确认）。 */
    public function pendingWorkCount(): int
    {
        $count = 0;
        foreach ($this->sessions as $session) {
            $count += count($session->inboundQoS2);
        }
        foreach ($this->outboundPending as $pending) {
            $count += count($pending);
        }

        return $count;
    }

    /** @return list<int> */
    public function connectedFds(): array
    {
        $fds = [];
        foreach ($this->sessions as $fd => $session) {
            if ($session->connected) {
                $fds[] = (int) $fd;
            }
        }

        return $fds;
    }

    /**
     * 连接关闭时清理会话。
     *
     * - 当前无跨断连持久会话：无论 cleanSession，订阅与 QoS 暂存均随 fd 销毁
     * - sendWill 预留扩展（当前未发送 will MQTT 报文）
     */
    public function remove(int $fd, bool $sendWill = false): void
    {
        unset($sendWill);
        $session = $this->sessions[$fd] ?? null;
        if ($session === null) {
            unset($this->outboundPending[$fd]);

            return;
        }

        // 仅当 clientIndex 仍指向本 fd 时才清除，避免误删新连接索引
        if ($session->clientId !== '' && ($this->clientIndex[$session->clientId] ?? null) === $fd) {
            unset($this->clientIndex[$session->clientId]);
        }

        // 无持久会话：clean_session=0 也不跨断连保留订阅（见类注释）
        unset($this->sessions[$fd], $this->outboundPending[$fd]);
    }

    public function stats(): array
    {
        $connected = 0;
        $subscriptionCount = 0;
        foreach ($this->sessions as $session) {
            if ($session->connected) {
                $connected++;
                $subscriptionCount += count($session->subscriptions);
            }
        }

        return [
            'sessions' => count($this->sessions),
            'connected' => $connected,
            'subscriptions' => $subscriptionCount,
            'retained_topics' => count($this->retainedMessages),
            'pending_qos' => $this->pendingWorkCount(),
        ];
    }
}
