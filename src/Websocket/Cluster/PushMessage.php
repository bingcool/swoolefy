<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis Pub/Sub 或 Streams 消息体（JSON）。
 *
 * 只传 event + data，不在发布端编码 Socket.IO 包；投递时按本节点连接的 is_socketio 编码。
 *
 * Streams 模式下整包 JSON 存入 Stream 字段 payload；msg_id 可用于业务对账（框架内未做去重）。
 */
class PushMessage
{
    public const ACTION_PUSH_EVENT = 'push_event';
    public const ACTION_BROADCAST = 'broadcast';

    public static function event(array $targets, string $event, $data, string $source = ''): array
    {
        return [
            'msg_id' => self::uuid(),
            'action' => self::ACTION_PUSH_EVENT,
            'targets' => array_values($targets),
            'event' => $event,
            'data' => is_array($data) ? $data : ['value' => $data],
            'opcode' => WEBSOCKET_OPCODE_TEXT,
            'source' => $source,
            'ts' => time(),
        ];
    }

    public static function broadcast(string $event, $data, string $source = ''): array
    {
        return [
            'msg_id' => self::uuid(),
            'action' => self::ACTION_BROADCAST,
            'targets' => [],
            'event' => $event,
            'data' => is_array($data) ? $data : ['value' => $data],
            'opcode' => WEBSOCKET_OPCODE_TEXT,
            'source' => $source,
            'ts' => time(),
        ];
    }

    public static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $payload): ?array
    {
        $message = json_decode($payload, true);

        return is_array($message) ? $message : null;
    }

    private static function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
