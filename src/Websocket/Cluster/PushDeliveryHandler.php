<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 本节点最终投递层：将 Redis Pub/Sub 消息转为 server->push()。
 *
 * ## 与引用模式的关系
 *
 * Pub/Sub 消息中的 `data` 可以是轻量引用（仅 msg_id）。本类不直接查库，
 * 统一委托 `WebsocketConnectionManager::deliverEventToFdLocally()`，
 * 由 `PushPayloadResolver` + 业务 enricher 在投递前加载完整消息。
 *
 * 编码逻辑复用 WebsocketConnectionManager::encodeEventPayload（区分 Socket.IO / 原生 WS）。
 */
class PushDeliveryHandler
{
    /**
     * 处理一条集群推送指令。
     *
     * @param array $message Pub/Sub 反序列化后的消息体，含 action / event / data / targets
     *
     * @return int 成功推送的连接数
     */
    public static function deliver(Server $server, array $message): int
    {
        $action = (string) ($message['action'] ?? PushMessage::ACTION_PUSH_EVENT);
        $event = (string) ($message['event'] ?? '');
        $data = $message['data'] ?? [];

        if ($action === PushMessage::ACTION_BROADCAST) {
            // broadcast：遍历本节点 Table，每个 fd 独立走 enricher 后 push
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
            // 精准扇出到本节点 fd；data 可为 { msg_id } 引用，enricher 在此展开
            if (WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }
}
