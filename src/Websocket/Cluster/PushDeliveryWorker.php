<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Swfy;

/**
 * 推送载荷 → 本节点 WebSocket 连接的统一投递入口。
 *
 * 被以下路径调用：
 * - PushStreamConsumer（streams）
 * - WebsocketPushSubscriberProcess / WebsocketPushDeliveryProcess（pubsub）
 *
 * 解码 JSON → PushDeliveryHandler::deliver → enricher → server->push()
 */
class PushDeliveryWorker
{
    /**
     * @param string $payload PushMessage::encode() 的 JSON
     */
    public static function deliverEncodedPayload(string $payload): void
    {
        $message = PushMessage::decode($payload);
        if ($message === null) {
            return;
        }

        $server = Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            return;
        }

        PushDeliveryHandler::deliver($server, $message);
    }
}
