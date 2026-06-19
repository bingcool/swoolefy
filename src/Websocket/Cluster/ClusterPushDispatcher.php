<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 集群模式推送分发器（WebSocket Worker 内）。
 *
 * 委托 ClusterPushBus，并传入 $server：
 * - 本节点 targets → PushDeliveryHandler 直推（少一次 Redis 往返）
 * - 远端节点 → Redis Pub/Sub 扇出
 *
 * 外部进程请用 ExternalPushPublisher，不要走本类。
 */
class ClusterPushDispatcher implements PushDispatcherInterface
{
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        $connId = WebsocketConnectionManager::getConnIdByFd($fd);
        if ($connId === '') {
            // 无 conn_id 时降级为本机直推（兼容未登记连接）
            return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
        }

        $meta = RedisConnectionRegistry::getConnectionMeta($connId);
        if ($meta === null) {
            return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
        }

        return ClusterPushBus::publishToTargets([[
            'fd' => (int) ($meta['fd'] ?? $fd),
            'conn_id' => $connId,
            'server_id' => (string) ($meta['server_id'] ?? ''),
        ]], $event, $data, $server) > 0;
    }

    public function pushEventToUser(Server $server, string $userId, string $event, $data = []): int
    {
        return ClusterPushBus::publishToUser($userId, $event, $data, $server);
    }

    public function pushEventToGroup(Server $server, string $group, string $event, $data = []): int
    {
        return ClusterPushBus::publishToGroup($group, $event, $data, $server);
    }

    public function broadcastEvent(Server $server, string $event, $data = []): int
    {
        return ClusterPushBus::publishBroadcast($event, $data, $server);
    }
}
