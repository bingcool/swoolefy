<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Swfy;

/**
 * 将 Pub/Sub 或本地队列中的 JSON 载荷投递到本节点 WebSocket 连接。
 */
class PushDeliveryWorker
{
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
