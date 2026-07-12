<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoolefy\Core\Swfy;
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Simps\MQTT\Message\ConnAck;
use Simps\MQTT\Message\PingResp;
use Simps\MQTT\Message\PubAck;
use Simps\MQTT\Message\SubAck;
use Simps\MQTT\Message\UnSubAck;

/**
 * MQTT 3.1.1 Broker 事件基类。
 *
 * ## 扩展方式
 * 继承本类并实现：
 * - verify()      鉴权（用户名/密码）
 * - connect()     CONNECT 业务逻辑（通常调用 sessions()->bind()）
 * - disconnect()  断连清理（通常 sessions()->remove()）
 *
 * subscribe() / publish() / unSubscribe() 已提供默认 Broker 实现，可按需 override。
 *
 * ## 内置响应
 * connectAck / connectReject / pingReq / publishAck / publishRec / publishRel /
 * publishComp / subscribeAck / unSubscribeAck 为 final，保证协议一致性。
 */
abstract class MqttEventV3
{
    use MqttBrokerSupport;

    /** @var \Swoole\Http\Server|\Swoole\Server|\Swoole\WebSocket\Server */
    protected $server;

    protected int $fd;

    /** @var array<string, mixed> */
    protected array $data;

    /** @var array<int, string> */
    public static array $eventMaps = [
        Types::CONNECT => 'connect',
        Types::CONNACK => 'connectAck',
        Types::PUBLISH => 'publish',
        Types::PUBACK => 'pubAck',
        Types::PUBREC => 'pubRec',
        Types::PUBREL => 'pubRel',
        Types::PUBCOMP => 'pubComp',
        Types::SUBSCRIBE => 'subscribe',
        Types::SUBACK => 'subAck',
        Types::UNSUBSCRIBE => 'unSubscribe',
        Types::UNSUBACK => 'unSubscribeAck',
        Types::PINGREQ => 'pingReq',
        Types::PINGRESP => 'pingResp',
        Types::DISCONNECT => 'disconnect',
    ];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(int $fd, array $data)
    {
        $this->server = Swfy::getServer();
        $this->fd = $fd;
        $this->data = $data;
    }

    abstract public function verify($username, $password): bool;

    abstract public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $clean_session,
        array $will = [],
    ): bool;

    abstract public function disconnect();

    public function publish($topic, $message, $dup, $qos, $retain, $message_id): void
    {
        // 委托 Trait：按订阅表路由，publisherFd 用于 no_local 过滤
        $this->dispatchPublish(
            (string) $topic,
            (string) $message,
            (bool) $dup,
            (int) $qos,
            (bool) $retain,
            $message_id ?? 0,
            $this->fd,
        );
    }

    /**
     * SUBSCRIBE 处理：写订阅表 + 推送 Retain + 返回 SUBACK codes（由 Dispatcher 发送 ACK）。
     *
     * @return array<int> granted QoS 列表
     */
    public function subscribe($type, $topics, $message_id): array
    {
        unset($type);
        // 写入 SessionManager 订阅表，返回 SUBACK granted codes
        $codes = $this->sessions()->subscribe($this->fd, (array) $topics, MQTT_PROTOCOL_LEVEL3);

        // 每个成功订阅的 filter 立即推送匹配的 Retain 消息
        foreach ((array) $topics as $filter => $qos) {
            if (!is_string($filter)) {
                continue;
            }
            $granted = is_numeric($qos) ? (int) $qos : 0;
            if ($granted <= 2) {
                $this->deliverRetainedOnSubscribe($this->fd, $filter, $granted);
            }
        }

        return $codes;
    }

    public function unSubscribe($type, $topics, $message_id): void
    {
        unset($type);
        $this->sessions()->unsubscribe($this->fd, (array) $topics);
        // V3 unSubscribe 内直接发 UNSUBACK（Dispatcher 不再重复发送）
        $this->unSubscribeAck($message_id);
    }

    final public function connectAck($clean_session): void
    {
        $this->server->send(
            $this->fd,
            (new ConnAck())->setCode(0)->setSessionPresent($clean_session),
        );
    }

    /** 鉴权失败时发送 CONNACK 拒绝码（默认 5=Not authorized）后由 Dispatcher 关闭连接。 */
    final public function connectReject(int $code = 5, bool $sessionPresent = false): void
    {
        if ($this->server->exists($this->fd)) {
            $this->server->send(
                $this->fd,
                (new ConnAck())->setCode($code)->setSessionPresent($sessionPresent),
            );
        }
    }

    final public function pingReq(): void
    {
        $this->server->send($this->fd, new PingResp());
    }

    final public function publishAck($message_id): void
    {
        $this->server->send(
            $this->fd,
            (new PubAck())->setMessageId($message_id ?? ''),
        );
    }

    final public function publishRec($message_id): void
    {
        $this->packAndSend($this->fd, [
            'type' => Types::PUBREC,
            'message_id' => $message_id ?? 0,
        ]);
    }

    final public function publishRel($message_id): void
    {
        $this->packAndSend($this->fd, [
            'type' => Types::PUBREL,
            'message_id' => $message_id ?? 0,
        ]);
    }

    final public function publishComp($message_id): void
    {
        $this->packAndSend($this->fd, [
            'type' => Types::PUBCOMP,
            'message_id' => $message_id ?? 0,
        ]);
    }

    /**
     * @param array<int|string> $payload
     */
    final public function subscribeAck($message_id, $payload): void
    {
        $this->server->send(
            $this->fd,
            (new SubAck())->setMessageId($message_id ?? '')->setCodes($payload),
        );
    }

    final public function unSubscribeAck($message_id): void
    {
        $this->server->send(
            $this->fd,
            (new UnSubAck())->setMessageId($message_id ?? ''),
        );
    }

    protected function getBrokerServer(): \Swoole\Server
    {
        return $this->server;
    }

    protected function getBrokerFd(): int
    {
        return $this->fd;
    }

    protected function getBrokerProtocolLevel(): int
    {
        return MQTT_PROTOCOL_LEVEL3;
    }

    protected function packAndSend(int $fd, array $packet): bool
    {
        if (!$this->server->exists($fd)) {
            return false; // 目标连接已断开
        }

        return (bool) $this->server->send($fd, Protocol\V3::pack($packet));
    }
}
