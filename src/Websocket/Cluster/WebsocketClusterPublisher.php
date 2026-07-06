<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 集群推送入口（仅 WebSocket Worker 进程内）。
 *
 * 通过 `Swfy::getServer()` 获取本机 Server，经 PushDispatcherFactory 分发推送。
 * HTTP/CLI 等外部进程请使用 ExternalPushPublisher。
 *
 * @see ExternalPushPublisher
 * @see PushDispatcherFactory
 */
class WebsocketClusterPublisher
{
    /** 向小组推送（集群扇出 + 本节点直推优化） */
    public static function pushToGroup(string $group, string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->pushEventToGroup($server, $group, $event, $data);
    }

    /** 向用户推送 */
    public static function pushToUser(string $userId, string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->pushEventToUser($server, $userId, $event, $data);
    }

    /** 全集群广播 */
    public static function broadcast(string $event, $data = []): int
    {
        $server = \Swoolefy\Core\Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            throw new ClusterRedisException('WebSocket server is not available for cluster publish');
        }

        return PushDispatcherFactory::get()->broadcastEvent($server, $event, $data);
    }
}
