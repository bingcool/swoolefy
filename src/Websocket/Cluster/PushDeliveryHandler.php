<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 本节点最终投递层：将 Pub/Sub 消息转为 server->push()。
 * 编码逻辑复用 WebsocketConnectionManager::encodeEventPayload（区分 Socket.IO / 原生 WS）。
 */
class PushDeliveryHandler
{
    public static function deliver(Server $server, array $message): int
    {
        $action = (string) ($message['action'] ?? PushMessage::ACTION_PUSH_EVENT);
        $event = (string) ($message['event'] ?? '');
        $data = $message['data'] ?? [];

        if ($action === PushMessage::ACTION_BROADCAST) {
            // broadcast 指令：遍历本节点本地 Table 全量投递
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
            if (WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }
}
