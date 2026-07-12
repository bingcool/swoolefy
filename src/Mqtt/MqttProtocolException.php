<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use RuntimeException;

/**
 * MQTT 协议层业务异常。
 *
 * 由 SessionManager / Dispatcher 抛出，携带 fd 与可选 CONNACK/Reason 码供上层日志使用。
 */
final class MqttProtocolException extends RuntimeException
{
    public function __construct(
        string $message,
        /** 触发异常的连接 fd */
        public readonly int $fd = 0,
        /** MQTT CONNACK / Reason Code（0 表示未设置） */
        public readonly int $reasonCode = 0,
    ) {
        parent::__construct($message);
    }
}
