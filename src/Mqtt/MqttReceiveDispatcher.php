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
 * | 方向 | QoS | 行为 |
 * |------|-----|------|
 * | 入站 | 0   | 直接 dispatchPublish |
 * | 入站 | 1   | dispatch + PUBACK |
 * | 入站 | 2   | 暂存 → PUBREC；PUBREL 后 dispatch → PUBCOMP |
 * | 出站 | 1   | PUBLISH → 等 PUBACK |
 * | 出站 | 2   | PUBLISH → 等 PUBREC → 发 PUBREL → 等 PUBCOMP |
 *
 * ## 优雅停机
 * - CONNECT：CONNACK 拒绝并关连接
 * - 新 SUBSCRIBE / 新 PUBLISH：拒绝（关连接或忽略）
 * - PING / PUBREL / PUBACK / PUBREC / PUBCOMP：放行，排空在途 QoS
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
     * 决定本包解码器用的协议级别
     *
     * - 已 CONNECT：固定用 Session.protocolLevel
     * - 未 CONNECT 且 auto_protocol：仅允许从 CONNECT 报文探测
     * - 未 CONNECT 且 auto_protocol 收到非 CONNECT：协议错误
     *
     * @param array<string, mixed>|null $mqttConf
     */
    public static function resolveReceiveProtocolLevel(int $fd, string $raw, ?array $mqttConf = null): int
    {
        $mqttConf ??= (array) (Swfy::getConf()['mqtt'] ?? []);
        $session = MqttSessionManager::getInstance()->get($fd);

        // CONNECT 成功后始终以 Session 协议级别为准（V3/V5 交错连接互不干扰）
        if ($session !== null && $session->connected) {
            return $session->protocolLevel === MQTT_PROTOCOL_LEVEL5
                ? MQTT_PROTOCOL_LEVEL5
                : MQTT_PROTOCOL_LEVEL3;
        }

        $auto = ($mqttConf['auto_protocol'] ?? false) === true;
        $default = (int) ($mqttConf['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3);
        $default = $default === MQTT_PROTOCOL_LEVEL5 ? MQTT_PROTOCOL_LEVEL5 : MQTT_PROTOCOL_LEVEL3;

        if (!$auto) {
            return $default;
        }

        // 仅 CONNECT 前允许 auto detect（依赖 simps/mqtt；缺失时 fail closed）
        if (!class_exists(Protocol\V3::class)) {
            throw new MqttProtocolException('MQTT non-CONNECT packet without session', $fd);
        }

        $peek = Protocol\V3::unpack($raw);
        if (is_array($peek) && ($peek['type'] ?? null) === Types::CONNECT) {
            return self::resolveProtocolLevel($peek);
        }

        throw new MqttProtocolException('MQTT non-CONNECT packet without session', $fd);
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

    /**
     * 按协议级别选择 Event Handler；auto_protocol 下避免 V3 类被用于 V5 路径。
     */
    private static function resolveEventClass(int $protocolLevel): string
    {
        $conf = (array) (Swfy::getConf()['mqtt'] ?? []);
        $configured = $conf['mqtt_event_handler'] ?? null;

        if ($protocolLevel === MQTT_PROTOCOL_LEVEL5) {
            if (is_string($configured) && is_a($configured, MqttEventV5::class, true)) {
                return $configured;
            }

            return ProductionMqttEventV5::class;
        }

        if (is_string($configured) && is_a($configured, MqttEventV3::class, true)) {
            return $configured;
        }

        return ProductionMqttEventV3::class;
    }

    private static function dispatchV3(Server $server, int $fd, string $raw): bool
    {
        $data = Protocol\V3::unpack($raw);
        if (!is_array($data) || !isset($data['type'])) {
            self::close($server, $fd);

            throw new MqttProtocolException('Mqtt Packet parse missing type', $fd);
        }

        // 合法控制报文：刷新 keep_alive 计时
        MqttSessionManager::getInstance()->touchActivity($fd);

        $eventClass = self::resolveEventClass(MQTT_PROTOCOL_LEVEL3);
        /** @var MqttEventV3 $mqttEvent */
        $mqttEvent = new $eventClass($fd, $data);
        $type = (int) $data['type'];

        try {
            switch ($type) {
                case Types::CONNECT:
                    return self::handleConnectV3($server, $fd, $mqttEvent, $data);

                case Types::PINGREQ:
                    $mqttEvent->pingReq();
                    break;

                case Types::DISCONNECT:
                    $mqttEvent->disconnect();
                    self::close($server, $fd);
                    break;

                case Types::PUBLISH:
                    if (MqttShutdownCoordinator::shouldRejectNewWork()) {
                        // 停机中拒绝新 PUBLISH，避免扩大在途；已开始的 QoS2 走 PUBREL
                        self::close($server, $fd);
                        break;
                    }
                    self::handlePublish($mqttEvent, $fd, $data);
                    break;

                case Types::PUBREL:
                    // QoS2 第二阶段：释放暂存 payload 后真正路由，再回 PUBCOMP（停机中仍放行）
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
                    if (MqttShutdownCoordinator::shouldRejectNewWork()) {
                        self::close($server, $fd);
                        break;
                    }
                    $codes = $mqttEvent->subscribe($data['type'], $data['topics'], $data['message_id']);
                    $payload = array_map(static fn ($code) => chr((int) $code), $codes);
                    $mqttEvent->subscribeAck($data['message_id'], $payload);
                    break;

                case Types::UNSUBSCRIBE:
                    $mqttEvent->unSubscribe($data['type'], $data['topics'], $data['message_id'] ?? '');
                    $mqttEvent->unSubscribeAck($data['message_id'] ?? '');
                    break;

                case Types::PUBACK:
                case Types::PUBCOMP:
                    // 客户端最终确认 Broker 出站：QoS1→PUBACK；QoS2→PUBCOMP
                    MqttSessionManager::getInstance()->ackOutbound($fd, $data['message_id'] ?? 0);
                    break;

                case Types::PUBREC:
                    // 出站 QoS2：PUBREC → PUBREL，pending 保留到 PUBCOMP
                    self::handleOutboundPubRec($mqttEvent, $fd, $data['message_id'] ?? 0);
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

        // 合法控制报文：刷新 keep_alive 计时
        MqttSessionManager::getInstance()->touchActivity($fd);

        $eventClass = self::resolveEventClass(MQTT_PROTOCOL_LEVEL5);
        /** @var MqttEventV5 $mqttEvent */
        $mqttEvent = new $eventClass($fd, $data);
        $type = (int) $data['type'];

        try {
            switch ($type) {
                case Types::CONNECT:
                    return self::handleConnectV5($server, $fd, $mqttEvent, $data);

                case Types::AUTH:
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
                    if (MqttShutdownCoordinator::shouldRejectNewWork()) {
                        self::close($server, $fd);
                        break;
                    }
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
                    if (MqttShutdownCoordinator::shouldRejectNewWork()) {
                        self::close($server, $fd);
                        break;
                    }
                    $codes = $mqttEvent->subscribe($data['type'], $data['topics'], $data['message_id']);
                    $mqttEvent->subscribeAck($data['message_id'], $codes);
                    break;

                case Types::UNSUBSCRIBE:
                    $mqttEvent->unSubscribe($data['type'], $data['topics'], $data['message_id'] ?? '');
                    $mqttEvent->unSubscribeAck($data['message_id'] ?? '');
                    break;

                case Types::PUBACK:
                case Types::PUBCOMP:
                    MqttSessionManager::getInstance()->ackOutbound($fd, $data['message_id'] ?? 0);
                    break;

                case Types::PUBREC:
                    self::handleOutboundPubRec($mqttEvent, $fd, $data['message_id'] ?? 0);
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
     * Broker→Client QoS2：客户端 PUBREC 后回 PUBREL，pending 等到 PUBCOMP 再清。
     */
    private static function handleOutboundPubRec(
        MqttEventV3|MqttEventV5 $mqttEvent,
        int $fd,
        int|string $messageId,
    ): void {
        if (MqttSessionManager::getInstance()->markOutboundPubRec($fd, $messageId)) {
            $mqttEvent->publishRel($messageId);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function handleConnectV3(Server $server, int $fd, MqttEventV3 $mqttEvent, array $data): bool
    {
        // 优雅停机：拒绝新会话（MQTT 3.1.1 CONNACK=3 Server unavailable）
        if (MqttShutdownCoordinator::shouldRejectNewSessions()) {
            $mqttEvent->connectReject(3);
            self::close($server, $fd);

            return false;
        }

        // 协议名必须为 MQTT（3.1.1），否则 CONNACK=1 拒绝
        if (($data['protocol_name'] ?? '') !== 'MQTT') {
            $mqttEvent->connectReject(1);
            self::close($server, $fd);

            return false;
        }

        $username = $data['user_name'] ?? '';
        $password = $data['password'] ?? '';

        if (!$mqttEvent->verify($username, $password)) {
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

        // 无跨断连持久会话：session_present 恒 false（与 clean_session 字段解耦）
        $mqttEvent->connectAck(false);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function handleConnectV5(Server $server, int $fd, MqttEventV5 $mqttEvent, array $data): bool
    {
        if (MqttShutdownCoordinator::shouldRejectNewSessions()) {
            $mqttEvent->connectReject(ReasonCode::SERVER_SHUTTING_DOWN);
            self::close($server, $fd);

            return false;
        }

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

        // session_present=false：当前无持久会话恢复
        $mqttEvent->connectAck(false);

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

        $mqttEvent->publish(
            $data['topic'],
            $data['message'],
            $data['dup'] ?? false,
            $qos,
            $data['retain'] ?? false,
            $messageId,
        );

        if ($qos === 1) {
            $mqttEvent->publishAck($messageId);
        }
    }

    private static function close(Server $server, int $fd): void
    {
        MqttSessionManager::getInstance()->remove($fd);
        if ($server->exists($fd)) {
            $server->close($fd);
        }
    }
}
