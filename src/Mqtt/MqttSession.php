<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

/**
 * 单连接 MQTT 会话快照（Worker 进程内有效）。
 *
 * 生命周期：
 *   TCP connect → CONNECT 报文 → bind() 创建会话 → SUBSCRIBE/PUBLISH → DISCONNECT/close → remove()
 *
 * 注意：fd 仅在所属 Worker 内唯一；跨 Worker 不可共享此对象。
 */
final class MqttSession
{
    /**
     * 当前连接订阅表。
     *
     * @var array<string, array{qos:int,no_local:bool}> topic filter => 订阅选项
     */
    public array $subscriptions = [];

    /**
     * 客户端 PUBLISH QoS2 暂存区（等待 PUBREL 后才真正 dispatch）。
     *
     * @var array<int, array{topic:string,message:string,qos:int,retain:bool,bytes:int,created_at:int}>
     */
    public array $inboundQoS2 = [];

    /** 最近一次合法控制报文时间（Unix 秒），供 keep_alive × 1.5 超时判断 */
    public int $lastActiveAt = 0;

    public function __construct(
        /** Swoole 连接 fd（Worker 内唯一） */
        public readonly int $fd,
        /** MQTT CONNECT 报文中的 client_id */
        public string $clientId = '',
        /** 认证用户名（可为空） */
        public string $username = '',
        /** 心跳 keep alive 秒数，0 表示禁用该连接的 keep-alive 超时 */
        public int $keepAlive = 0,
        /** 协议级别：4=MQTT3.1.1，5=MQTT5.0（auto_protocol 后续报文以此为准） */
        public int $protocolLevel = MQTT_PROTOCOL_LEVEL3,
        /** 是否已完成 CONNECT 握手 */
        public bool $connected = false,
        /** CONNECT clean session 标志 */
        public bool $cleanSession = true,
        /** 会话建立 Unix 时间戳 */
        public int $connectedAt = 0,
    ) {
        // 未显式传入时使用当前时间作为连接建立时刻
        if ($this->connectedAt === 0) {
            $this->connectedAt = time();
        }
        if ($this->lastActiveAt === 0) {
            $this->lastActiveAt = $this->connectedAt;
        }
    }

    /** 刷新活跃时间（收到合法控制报文时调用） */
    public function touch(int $now = 0): void
    {
        $this->lastActiveAt = $now > 0 ? $now : time();
    }
}
