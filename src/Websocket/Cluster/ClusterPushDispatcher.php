<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 集群模式推送分发器（WebSocket Worker 内）。
 *
 * ## 职责
 *
 * 实现 PushDispatcherInterface，将业务推送请求委托给 ClusterPushBus，
 * 并传入本机 `$server` 实例以启用本节点直推优化。
 *
 * ## 与 ExternalPushPublisher 的区别
 *
 * | 入口 | $localServer | 本节点直推 |
 * |------|--------------|------------|
 * | ClusterPushDispatcher | Swfy::getServer() | 是 |
 * | ExternalPushPublisher | null | 否，全部走 Redis |
 *
 * ## pushEventToFd 降级
 *
 * conn_id 缺失或 Redis meta 已过期时，降级为 WebsocketConnectionManager 本机直推，
 * 兼容未登记连接或集群索引短暂不一致的场景。
 *
 * @see ClusterPushBus
 * @see ExternalPushPublisher
 */
class ClusterPushDispatcher implements PushDispatcherInterface
{
    /**
     * 向单个 fd 推送：查 Redis meta 后走 ClusterPushBus 扇出（通常仅本节点一条 target）。
     */
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        $connId = WebsocketConnectionManager::getConnIdByFd($fd);
        if ($connId === '') {
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
        return ClusterPushBus::publishToUser($userId, $event, $data, $server)->reportedHitCount();
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
