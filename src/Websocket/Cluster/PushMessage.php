<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 跨节点推送消息体（JSON 序列化）。
 *
 * ## 设计原则
 *
 * - 只传 **event + data**，不在发布端编码 Socket.IO 包
 * - 投递端（PushDeliveryHandler）按目标连接的 `is_socketio` 决定编码方式
 * - `data` 可为轻量引用（如 `{ msg_id }`），由 push.enricher 在投递前展开
 *
 * ## 消息结构
 *
 * **push_event（精准扇出）**：
 *
 * ```json
 * {
 *   "msg_id": "hex32",
 *   "trace_id": "uuid",
 *   "action": "push_event",
 *   "targets": [{"fd": 3, "conn_id": "host:9502:3"}],
 *   "event": "chat.message",
 *   "data": {"msg_id": "m-1001"},
 *   "opcode": 1,
 *   "source": "host:9502",
 *   "ts": 1710000000
 * }
 * ```
 *
 * **broadcast（节点内全量）**：
 *
 * ```json
 * {
 *   "action": "broadcast",
 *   "targets": [],
 *   "event": "system.notice",
 *   "data": {"text": "维护通知"}
 * }
 * ```
 *
 * Streams 模式下整包 JSON 存入 Stream 字段 `payload`。
 * `msg_id` 用于对账；`cluster.push.dedup.enable=true` 时框架用 Redis SET+TTL 去重（防 XAUTOCLAIM 重投）。
 *
 * @see PushStreamPublisher
 * @see PushDedupStore
 * @see PushDeliveryHandler
 */
class PushMessage
{
    /** 精准扇出到 targets 列表 */
    public const ACTION_PUSH_EVENT = 'push_event';

    /** 目标节点遍历本地 Table 全量投递 */
    public const ACTION_BROADCAST = 'broadcast';

    /**
     * 构造 push_event 消息。
     *
     * @param array<int, array{fd: int, conn_id: string}> $targets 同一 server_id 下的 fd 列表
     * @param string                                      $source  来源 server_id 或 "external"
     * @param string                                      $traceId 链路 trace；空则自动生成
     * @param string                                      $msgId   推送指令 ID；空则自动生成（去重 / 对账用）
     */
    public static function event(
        array $targets,
        string $event,
        $data,
        string $source = '',
        string $traceId = '',
        string $msgId = '',
        ?string $recipientUserId = null,
        ?string $fanoutGroup = null,
        string $fanoutScope = 'targets'
    ): array {
        $message = [
            'msg_id' => $msgId !== '' ? $msgId : self::uuid(),
            'trace_id' => $traceId !== '' ? $traceId : \Swoolefy\Websocket\Metrics\WebsocketTraceContext::currentOrNew(),
            'action' => self::ACTION_PUSH_EVENT,
            'targets' => array_values($targets),
            'event' => $event,
            'data' => is_array($data) ? $data : ['value' => $data],
            'opcode' => WEBSOCKET_OPCODE_TEXT,
            'source' => $source,
            'ts' => time(),
        ];

        $recipientUserId = $recipientUserId !== null ? trim($recipientUserId) : '';
        if ($recipientUserId !== '') {
            $message['recipient_user_id'] = $recipientUserId;
        }

        $fanoutGroup = $fanoutGroup !== null ? trim($fanoutGroup) : '';
        if ($fanoutGroup !== '') {
            $message['fanout_group'] = $fanoutGroup;
        }

        if ($fanoutScope !== '' && $fanoutScope !== 'targets') {
            $message['fanout_scope'] = $fanoutScope;
        }

        return $message;
    }

    /** 构造 broadcast 消息（targets 为空，由目标节点本地遍历） */
    public static function broadcast(string $event, $data, string $source = '', string $traceId = '', string $msgId = ''): array
    {
        return [
            'msg_id' => $msgId !== '' ? $msgId : self::uuid(),
            'trace_id' => $traceId !== '' ? $traceId : \Swoolefy\Websocket\Metrics\WebsocketTraceContext::currentOrNew(),
            'action' => self::ACTION_BROADCAST,
            'targets' => [],
            'event' => $event,
            'data' => is_array($data) ? $data : ['value' => $data],
            'opcode' => WEBSOCKET_OPCODE_TEXT,
            'source' => $source,
            'ts' => time(),
        ];
    }

    /** 序列化为 JSON 字符串（Pub/Sub payload 或 Stream field） */
    public static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE);
    }

    /** 反序列化；非法 JSON 返回 null */
    public static function decode(string $payload): ?array
    {
        $message = json_decode($payload, true);

        return is_array($message) ? $message : null;
    }

    /** 生成 32 位十六进制消息 ID */
    private static function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
