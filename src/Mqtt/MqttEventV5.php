<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Simps\MQTT\Hex\ReasonCode;
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Swoolefy\Core\Swfy;

/**
 * MQTT 5.0 Broker 事件基类。
 *
 * 与 {@see MqttEventV3} 类似，额外支持：
 * - auth() 增强认证流程
 * - connect() 接收 properties（session expiry、auth method 等）
 * - subscribe() 支持 no_local 等 V5 订阅选项
 */
abstract class MqttEventV5
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
        Types::AUTH => 'auth',
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

    abstract public function verify(
        $username,
        $password,
        $authentication_method,
        $authentication_data,
    ): bool;

    abstract public function auth($code, array $properties);

    abstract public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $properties,
        $clean_session,
        array $will = [],
    ): bool;

    abstract public function disconnect();

    public function publish($topic, $message, $dup, $qos, $retain, $message_id): void
    {
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
     * @return array<int> granted QoS / reason codes for SUBACK
     */
    public function subscribe($type, $topics, $message_id): array
    {
        unset($type);
        $codes = $this->sessions()->subscribe($this->fd, (array) $topics, MQTT_PROTOCOL_LEVEL5);

        foreach ((array) $topics as $filter => $option) {
            if (!is_string($filter)) {
                continue;
            }
            // V5 option 可能是 ['qos'=>..,'no_local'=>..] 或纯整数
            $qos = is_array($option) ? (int) ($option['qos'] ?? 0) : (int) $option;
            if ($qos <= 2) {
                $this->deliverRetainedOnSubscribe($this->fd, $filter, $qos);
            }
        }

        return $codes;
    }

    public function unSubscribe($type, $topics, $message_id): void
    {
        unset($type);
        $this->sessions()->unsubscribe($this->fd, (array) $topics);
        $this->unSubscribeAck($message_id);
    }

    /**
     * @param bool $sessionPresent 是否存在可恢复会话；当前实现无持久会话，Dispatcher 传 false
     */
    public function connectAck($sessionPresent, array $properties = []): void
    {
        // 默认 Broker 能力声明，可被子类传入 properties 覆盖
        $properties = array_merge([
            'maximum_packet_size' => 1048576,
            'retain_available' => true,
            'shared_subscription_available' => true,
            'subscription_identifier_available' => true,
            'topic_alias_maximum' => 65535,
            'wildcard_subscription_available' => true,
        ], $properties);

        $this->server->send(
            $this->fd,
            Protocol\V5::pack([
                'type' => Types::CONNACK,
                'code' => 0,
                'session_present' => (bool) $sessionPresent,
                'properties' => $properties,
            ]),
        );
    }

    public function connectReject(int $code = ReasonCode::NOT_AUTHORIZED, bool $sessionPresent = false, array $properties = []): void
    {
        if (!$this->server->exists($this->fd)) {
            return;
        }

        $this->server->send(
            $this->fd,
            Protocol\V5::pack([
                'type' => Types::CONNACK,
                'code' => $code,
                'session_present' => $sessionPresent,
                'properties' => $properties,
            ]),
        );
    }

    final public function pingReq(): void
    {
        $this->server->send($this->fd, Protocol\V5::pack(['type' => Types::PINGRESP]));
    }

    final public function publishAck($message_id): void
    {
        $this->packAndSend($this->fd, [
            'type' => Types::PUBACK,
            'message_id' => $message_id ?? '',
        ]);
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
            Protocol\V5::pack([
                'type' => Types::SUBACK,
                'message_id' => $message_id ?? '',
                'codes' => $payload,
            ]),
        );
    }

    final public function unSubscribeAck($message_id): void
    {
        $this->server->send(
            $this->fd,
            Protocol\V5::pack([
                'type' => Types::UNSUBACK,
                'message_id' => $message_id ?? '',
            ]),
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
        return MQTT_PROTOCOL_LEVEL5;
    }

    protected function packAndSend(int $fd, array $packet): bool
    {
        if (!$this->server->exists($fd)) {
            return false;
        }

        return (bool) $this->server->send($fd, Protocol\V5::pack($packet));
    }
}
