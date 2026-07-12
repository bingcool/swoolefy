<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Simps\MQTT\Hex\ReasonCode;
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Swoole\Server;
use Swoolefy\Core\Swfy;

/**
 * MQTT 报文统一分发器（V3 / V5 生产级 Broker 语义）。
 *
 * ## 流程
 *   MqttServer::onReceive → dispatch() → unpack → new EventHandler → switch(type)
 *
 * ## 与 EventHandler 分工
 * - Dispatcher：协议状态机（QoS2 握手、CONNACK 拒绝、SUBACK 组装）
 * - MqttEventV3/V5：业务钩子 verify/connect + 默认 subscribe/publish 路由
 *
 * ## QoS 处理
 * | QoS | 行为 |
 * |-----|------|
 * | 0   | 直接 dispatchPublish |
 * | 1   | dispatch + PUBACK |
 * | 2   | 暂存 → PUBREC；PUBREL 后 dispatch → PUBCOMP |
 */
final class MqttReceiveDispatcher
{
    /**
     * 解析并分发原始 TCP 负载。
     *
     * @param int $protocolLevel MQTT_PROTOCOL_LEVEL3 或 MQTT_PROTOCOL_LEVEL5
     */
    public static function dispatch(Server $server, int $fd, string $raw, int $protocolLevel): bool
    {
        if ($protocolLevel === MQTT_PROTOCOL_LEVEL5) {
            return self::dispatchV5($server, $fd, $raw);
        }

        return self::dispatchV3($server, $fd, $raw);
    }

    /**
     * 从配置或 CONNECT 报文解析协议级别。
     *
     * conf.mqtt.auto_protocol=true 时以 CONNECT.protocol_level 为准。
     */
    public static function resolveProtocolLevel(?array $connectPacket = null): int
    {
        $conf = (array) (Swfy::getConf()['mqtt'] ?? []);
        // auto_protocol：以 CONNECT 报文中的 protocol_level 为准
        if (($conf['auto_protocol'] ?? false) && $connectPacket !== null) {
            $level = (int) ($connectPacket['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3);
            return $level === MQTT_PROTOCOL_LEVEL5 ? MQTT_PROTOCOL_LEVEL5 : MQTT_PROTOCOL_LEVEL3;
        }

        // 否则使用 conf 固定值，非 5 一律按 V3 处理
        $level = (int) ($conf['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3);

        return $level === MQTT_PROTOCOL_LEVEL5 ? MQTT_PROTOCOL_LEVEL5 : MQTT_PROTOCOL_LEVEL3;
    }

    private static function dispatchV3(Server $server, int $fd, string $raw): bool
    {
        $data = Protocol\V3::unpack($raw);
        if (!is_array($data) || !isset($data['type'])) {
            self::close($server, $fd);

            throw new MqttProtocolException('Mqtt Packet parse missing type', $fd);
        }

        $conf = Swfy::getConf();
        $eventClass = $conf['mqtt']['mqtt_event_handler'] ?? ProductionMqttEventV3::class;
        /** @var MqttEventV3 $mqttEvent */
        $mqttEvent = new $eventClass($fd, $data);
        $type = (int) $data['type'];

        try {
            switch ($type) {
                case Types::CONNECT:
                    // CONNECT 单独走鉴权 + bind 流程，成功后才 CONNACK
                    return self::handleConnectV3($server, $fd, $mqttEvent, $data);

                case Types::PINGREQ:
                    $mqttEvent->pingReq();
                    break;

                case Types::DISCONNECT:
                    $mqttEvent->disconnect();
                    self::close($server, $fd);
                    break;

                case Types::PUBLISH:
                    self::handlePublish($mqttEvent, $fd, $data);
                    break;

                case Types::PUBREL:
                    // QoS2 第二阶段：释放暂存 payload 后真正路由，再回 PUBCOMP
                    $payload = MqttSessionManager::getInstance()->releaseInboundQoS2($fd, $data['message_id'] ?? 0);
                    if ($payload !== null) {
                        $mqttEvent->publish(
                            $payload['topic'],
                            $payload['message'],
                            false,
                            $payload['qos'],
                            $payload['retain'],
                            0,
                        );
                    }
                    $mqttEvent->publishComp($data['message_id'] ?? 0);
                    break;

                case Types::SUBSCRIBE:
                    // Event 返回 granted codes，Dispatcher 负责组 SUBACK
                    $codes = $mqttEvent->subscribe($data['type'], $data['topics'], $data['message_id']);
                    $payload = array_map(static fn ($code) => chr((int) $code), $codes);
                    $mqttEvent->subscribeAck($data['message_id'], $payload);
                    break;

                case Types::UNSUBSCRIBE:
                    $mqttEvent->unSubscribe($data['type'], $data['topics'], $data['message_id'] ?? '');
                    $mqttEvent->unSubscribeAck($data['message_id'] ?? '');
                    break;

                case Types::PUBACK:
                case Types::PUBREC:
                case Types::PUBCOMP:
                    // 客户端对 Broker 下发 QoS1/2 的确认，Broker 侧暂不追踪 outbound 状态
                    break;

                default:
                    throw new MqttProtocolException("Mqtt Packet type={$type} error", $fd);
            }
        } catch (\Throwable $exception) {
            self::close($server, $fd);
            throw $exception;
        }

        return true;
    }

    private static function dispatchV5(Server $server, int $fd, string $raw): bool
    {
        $data = Protocol\V5::unpack($raw);
        if (!is_array($data) || !isset($data['type'])) {
            self::close($server, $fd);

            throw new MqttProtocolException('Mqtt Packet parse missing type', $fd);
        }

        $conf = Swfy::getConf();
        $eventClass = $conf['mqtt']['mqtt_event_handler'] ?? ProductionMqttEventV5::class;
        /** @var MqttEventV5 $mqttEvent */
        $mqttEvent = new $eventClass($fd, $data);
        $type = (int) $data['type'];

        try {
            switch ($type) {
                case Types::CONNECT:
                    return self::handleConnectV5($server, $fd, $mqttEvent, $data);

                case Types::AUTH:
                    // 增强认证（Challenge/Response），默认 Production 空实现
                    $mqttEvent->auth($data['code'], $data['properties'] ?? []);
                    break;

                case Types::PINGREQ:
                    $mqttEvent->pingReq();
                    break;

                case Types::DISCONNECT:
                    $mqttEvent->disconnect();
                    self::close($server, $fd);
                    break;

                case Types::PUBLISH:
                    self::handlePublish($mqttEvent, $fd, $data);
                    break;

                case Types::PUBREL:
                    $payload = MqttSessionManager::getInstance()->releaseInboundQoS2($fd, $data['message_id'] ?? 0);
                    if ($payload !== null) {
                        $mqttEvent->publish(
                            $payload['topic'],
                            $payload['message'],
                            false,
                            $payload['qos'],
                            $payload['retain'],
                            0,
                        );
                    }
                    $mqttEvent->publishComp($data['message_id'] ?? 0);
                    break;

                case Types::SUBSCRIBE:
                    // V5 SUBACK codes 为整数 reason code，无需 chr 转换
                    $codes = $mqttEvent->subscribe($data['type'], $data['topics'], $data['message_id']);
                    $mqttEvent->subscribeAck($data['message_id'], $codes);
                    break;

                case Types::UNSUBSCRIBE:
                    $mqttEvent->unSubscribe($data['type'], $data['topics'], $data['message_id'] ?? '');
                    $mqttEvent->unSubscribeAck($data['message_id'] ?? '');
                    break;

                case Types::PUBACK:
                case Types::PUBREC:
                case Types::PUBCOMP:
                    break;

                default:
                    throw new MqttProtocolException("Mqtt Packet type={$type} error", $fd);
            }
        } catch (\Throwable $exception) {
            self::close($server, $fd);
            throw $exception;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function handleConnectV3(Server $server, int $fd, MqttEventV3 $mqttEvent, array $data): bool
    {
        // 协议名必须为 MQTT（3.1.1），否则 CONNACK=1 拒绝
        if (($data['protocol_name'] ?? '') !== 'MQTT') {
            $mqttEvent->connectReject(1);
            self::close($server, $fd);

            return false;
        }

        $username = $data['user_name'] ?? '';
        $password = $data['password'] ?? '';

        if (!$mqttEvent->verify($username, $password)) {
            // 鉴权失败：CONNACK=5 Not authorized，再关闭 TCP
            $mqttEvent->connectReject(5);
            self::close($server, $fd);

            return false;
        }

        $accepted = $mqttEvent->connect(
            $data['protocol_name'],
            $data['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3,
            $username,
            $password,
            $data['client_id'],
            $data['keep_alive'],
            $data['clean_session'] ?? 0,
            $data['will'] ?? [],
        );

        if (!$accepted) {
            $mqttEvent->connectReject(5);
            self::close($server, $fd);

            return false;
        }

        // session_present 由 clean_session 决定（简化实现，未做持久会话恢复）
        $mqttEvent->connectAck((bool) ($data['clean_session'] ?? 0));

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function handleConnectV5(Server $server, int $fd, MqttEventV5 $mqttEvent, array $data): bool
    {
        $properties = $data['properties'] ?? [];
        $username = $data['user_name'] ?? '';
        $password = $data['password'] ?? '';
        $authenticationMethod = $properties['authentication_method'] ?? '';
        $authenticationData = $properties['authentication_data'] ?? '';

        if (!$mqttEvent->verify($username, $password, $authenticationMethod, $authenticationData)) {
            $mqttEvent->connectReject(ReasonCode::NOT_AUTHORIZED);
            self::close($server, $fd);

            return false;
        }

        $accepted = $mqttEvent->connect(
            $data['protocol_name'],
            $data['protocol_level'] ?? MQTT_PROTOCOL_LEVEL5,
            $username,
            $password,
            $data['client_id'],
            $data['keep_alive'],
            $properties,
            $data['clean_session'] ?? 0,
            $data['will'] ?? [],
        );

        if (!$accepted) {
            $mqttEvent->connectReject(ReasonCode::NOT_AUTHORIZED);
            self::close($server, $fd);

            return false;
        }

        // connectAck 会附带 Broker 能力属性（retain_available 等）
        $mqttEvent->connectAck((bool) ($data['clean_session'] ?? 0));

        return true;
    }

    /**
     * 处理客户端 PUBLISH（QoS 分支）。
     *
     * QoS2 仅回复 PUBREC，真正 publish 延迟到 PUBREL。
     *
     * @param array<string, mixed> $data unpack 后的报文字段
     */
    private static function handlePublish(MqttEventV3|MqttEventV5 $mqttEvent, int $fd, array $data): void
    {
        $qos = (int) ($data['qos'] ?? 0);
        $messageId = $data['message_id'] ?? 0;

        if ($qos === 2) {
            // QoS2 第一阶段：只暂存 + 回 PUBREC，不立即路由（等 PUBREL）
            MqttSessionManager::getInstance()->rememberInboundQoS2(
                $fd,
                $messageId,
                (string) $data['topic'],
                (string) $data['message'],
                $qos,
                (bool) ($data['retain'] ?? false),
            );
            $mqttEvent->publishRec($messageId);

            return;
        }

        // QoS0/1：收到即路由
        $mqttEvent->publish(
            $data['topic'],
            $data['message'],
            $data['dup'] ?? false,
            $qos,
            $data['retain'] ?? false,
            $messageId,
        );

        if ($qos === 1) {
            // QoS1：路由完成后回 PUBACK
            $mqttEvent->publishAck($messageId);
        }
    }

    private static function close(Server $server, int $fd): void
    {
        // 先清会话再关 TCP，避免 close 回调重复 remove 时状态不一致
        MqttSessionManager::getInstance()->remove($fd);
        if ($server->exists($fd)) {
            $server->close($fd);
        }
    }
}
