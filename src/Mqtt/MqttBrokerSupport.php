<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoole\Atomic;
use Swoole\Server;

/**
 * Broker 公共能力 Trait —— 供 MqttEventV3 / MqttEventV5 复用。
 *
 * 提供：
 * - dispatchPublish：按订阅表路由 PUBLISH（协商 delivery QoS = min(pub, sub)）
 * - deliverRetainedOnSubscribe：新 SUBSCRIBE 时推送匹配的 Retain 消息
 * - nextMessageId：Worker 内全局 Atomic 生成 1~65535（多协程安全；pending 仍按 fd 隔离）
 *
 * 子类需实现 packAndSend() 以区分 V3/V5 报文编码。
 */
trait MqttBrokerSupport
{
    abstract protected function getBrokerServer(): Server;

    abstract protected function getBrokerFd(): int;

    abstract protected function getBrokerProtocolLevel(): int;

    /**
     * 编码并发送 MQTT 报文到指定 fd。
     *
     * @param array<string, mixed> $packet simps/mqtt pack 数组结构
     */
    abstract protected function packAndSend(int $fd, array $packet): bool;

    protected function sessions(): MqttSessionManager
    {
        return MqttSessionManager::getInstance();
    }

    /**
     * 将 PUBLISH 分发给所有匹配订阅者（非广播全连接）。
     *
     * - retain=true 时写入 SessionManager Retain 表
     * - 发布者 fd 默认参与 no_local 过滤（V5）；V3 仍会被 matchSubscribers 排除同 fd 的 no_local=false 订阅
     * - delivery QoS = min(发布 QoS, 订阅 QoS)
     */
    protected function dispatchPublish(
        string $topic,
        string $message,
        bool $dup,
        int $qos,
        bool $retain,
        int|string $messageId = 0,
        ?int $publisherFd = null,
    ): void {
        $publisherFd ??= $this->getBrokerFd();
        $server = $this->getBrokerServer();

        // Retain 消息先入库，再路由（即使当前无订阅者也会保留）
        if ($retain) {
            $this->sessions()->storeRetained($topic, $message, $qos, true);
        }

        // 按 topic 匹配订阅表，非全连接广播
        $subscribers = $this->sessions()->matchSubscribers($topic, $publisherFd);
        foreach ($subscribers as $item) {
            $targetFd = (int) $item['fd'];
            if (!$server->exists($targetFd)) {
                continue; // 连接已断开但会话尚未清理
            }

            // 投递 QoS = min(发布 QoS, 订阅 QoS)（MQTT 规范）
            $deliveryQos = min($qos, (int) $item['qos']);
            // QoS0 不需要 message_id；QoS1/2 由 Broker 生成新 id
            $outMessageId = $deliveryQos > 0 ? $this->nextMessageId($targetFd) : ($messageId ?: 0);

            $sent = $this->packAndSend($targetFd, [
                'type' => \Simps\MQTT\Protocol\Types::PUBLISH,
                'topic' => $topic,
                'message' => $message,
                'dup' => $dup,
                'qos' => $deliveryQos,
                'retain' => $retain,
                'message_id' => $outMessageId,
            ]);
            // 出站 QoS1/2 记入 pending，优雅停机时等待客户端确认
            if ($sent && $deliveryQos > 0) {
                $this->sessions()->rememberOutbound($targetFd, (int) $outMessageId, $deliveryQos);
            }
        }
    }

    /**
     * SUBSCRIBE 成功后，向该 fd 推送所有匹配的 Retain 消息（MQTT 规范要求）。
     */
    protected function deliverRetainedOnSubscribe(int $fd, string $topicFilter, int $maxQos): void
    {
        $server = $this->getBrokerServer();
        if (!$server->exists($fd)) {
            return;
        }

        foreach ($this->sessions()->retainedForSubscription($topicFilter) as $topic => $retained) {
            // 订阅时推送 Retain：QoS 同样取 min(sub, retain)
            $deliveryQos = min($maxQos, (int) $retained['qos']);
            $outMessageId = $deliveryQos > 0 ? $this->nextMessageId($fd) : 0;
            $sent = $this->packAndSend($fd, [
                'type' => \Simps\MQTT\Protocol\Types::PUBLISH,
                'topic' => $topic,
                'message' => $retained['message'],
                'dup' => false,
                'qos' => $deliveryQos,
                'retain' => true, // 必须带 retain 标志，客户端才能识别为 Retain 消息
                'message_id' => $outMessageId,
            ]);
            if ($sent && $deliveryQos > 0) {
                $this->sessions()->rememberOutbound($fd, (int) $outMessageId, $deliveryQos);
            }
        }
    }

    /**
     * Broker 侧生成 outbound message_id（1~65535 循环）。
     *
     * 使用 Worker 进程内 {@see Atomic} + cmpset，避免多协程同时 publish 撞同一 id。
     * pending 表按 fd 隔离，全局序号跨连接共享不影响 ACK 配对。
     *
     * @param int $fd 目标连接（保留参数便于日后按 fd 扩展；当前未使用）
     */
    protected function nextMessageId(int $fd): int
    {
        unset($fd);

        static ?Atomic $seq = null;
        $seq ??= new Atomic(0);

        // CAS 循环：读当前值 → 算下一号（满则回 1）→ cmpset 成功才返回
        while (true) {
            $current = $seq->get();
            $next = $current >= 65535 ? 1 : $current + 1;
            if ($seq->cmpset($current, $next)) {
                return $next;
            }
        }
    }
}
