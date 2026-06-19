<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 集群推送入口（仅 WebSocket Worker 内）。
 *
 * 依赖 Swfy::getServer() 获取本机 Server 实例。
 * HTTP/CLI 等外部进程请用 ExternalPushPublisher（不依赖 Server）。
 */
class WebsocketClusterPublisher
{
    public static function pushToGroup(string $group, string $event, $data = []): int
    {
        // 必须在 WebSocket Worker 进程内调用
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->pushEventToGroup($server, $group, $event, $data);
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
