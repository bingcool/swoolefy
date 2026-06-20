<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 本节点最终投递层：将集群推送指令转为 `server->push()`。
 *
 * ## 在推送链路中的位置
 *
 * ```
 * 跨节点扇出（ClusterPushBus / ExternalPushPublisher）
 *   → Redis Streams XADD 或 Pub/Sub PUBLISH
 *   → PushStreamConsumer / WebsocketPushSubscriberProcess
 *   → PushDeliveryWorker::deliverEncodedPayload()
 *   → PushDeliveryHandler::deliver()（本类）
 *   → WebsocketConnectionManager::deliverEventToFdLocally()
 *   → PushPayloadResolver + enricher（可选）
 *   → encodeEventPayload（Socket.IO / 原生 WS）
 *   → server->push(fd, ...)
 * ```
 *
 * 本类运行在 **WebSocket Worker 或推送消费进程** 内，只处理**已路由到本节点**的 targets。
 *
 * ## 支持的 action
 *
 * | action | 行为 |
 * |--------|------|
 * | push_event | 向 message.targets 中的 fd 列表精准投递 |
 * | broadcast | 遍历本节点 Swoole\Table 全部连接广播 |
 *
 * ## 与引用模式（msg_id）的关系
 *
 * 本类不直接查库。`data` 可以是 `{ "msg_id": "m-1001" }` 等轻量引用，
 * 统一委托 `deliverEventToFdLocally()`，由 `push.enricher` 在 push 前展开完整载荷。
 *
 * ## 编码
 *
 * 不在此层区分协议；`encodeEventPayload()` 按连接 `is_socketio` 输出
 * `42["event",{...}]` 或原生 JSON event 包。
 *
 * @see PushMessage
 * @see PushDeliveryWorker
 * @see WebsocketConnectionManager::deliverEventToFdLocally()
 */
class PushDeliveryHandler
{
    /**
     * 处理一条集群推送指令（PushMessage 解码后的数组）。
     *
     * @param Server $server  本节点 Swoole WebSocket Server
     * @param array  $message 含 action / event / data / targets（见 PushMessage）
     *
     * @return int 成功 push 的连接数（fd 不存在或 enricher 返回 null 不计入）
     */
    public static function deliver(Server $server, array $message): int
    {
        $action = (string) ($message['action'] ?? PushMessage::ACTION_PUSH_EVENT);
        $event = (string) ($message['event'] ?? '');
        $data = $message['data'] ?? [];

        if ($action === PushMessage::ACTION_BROADCAST) {
            // 远端节点收到 broadcast 指令：只扫本机 Table，不再次 Redis 扇出
            return WebsocketConnectionManager::deliverBroadcastEventLocally($server, $event, $data);
        }

        $count = 0;
        $targets = is_array($message['targets'] ?? null) ? $message['targets'] : [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $fd = (int) ($target['fd'] ?? 0);
            if ($fd <= 0) {
                continue;
            }
            // 连接已断开时 deliverEventToFdLocally 返回 false，不抛异常
            if (WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }
}
