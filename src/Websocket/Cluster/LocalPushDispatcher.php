<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 单机模式推送分发器（cluster.enable=false 时使用）。
 *
 * 仅查询本地 Swoole\Table，不访问 Redis，适用于单节点部署或开发环境。
 *
 * @see ClusterPushDispatcher  集群模式对应实现
 */
class LocalPushDispatcher implements PushDispatcherInterface
{
    /** 向单个 fd 推送，走本地 Table + enricher + server->push() */
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
    }

    /** 遍历本地 Table 中该 user_id 的所有 fd */
    public function pushEventToUser(Server $server, string $userId, string $event, $data = []): int
    {
        $count = 0;
        foreach (array_unique(WebsocketConnectionManager::getFdsByUser($userId)) as $fd) {
            if ($this->pushEventToFd($server, (int) $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }

    /** 遍历本地 Table 中该 group 的所有 fd */
    public function pushEventToGroup(Server $server, string $group, string $event, $data = []): int
    {
        $count = 0;
        foreach (array_unique(WebsocketConnectionManager::getFdsByGroup($group)) as $fd) {
            if ($this->pushEventToFd($server, (int) $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }

    /** 遍历本节点全部连接广播 */
    public function broadcastEvent(Server $server, string $event, $data = []): int
    {
        return WebsocketConnectionManager::deliverBroadcastEventLocally($server, $event, $data);
    }
}
