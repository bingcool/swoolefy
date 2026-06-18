<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 集群推送入口（供 WebSocket worker 内调用）。
 * 与 WebSocketService::pushToRoom 等价，内部走 PushDispatcherFactory。
 */
class WebsocketClusterPublisher
{
    public static function pushToRoom(string $room, string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->pushEventToRoom($server, $room, $event, $data);
    }

    public static function pushToUser(string $userId, string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->pushEventToUser($server, $userId, $event, $data);
    }

    public static function broadcast(string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->broadcastEvent($server, $event, $data);
    }
}
