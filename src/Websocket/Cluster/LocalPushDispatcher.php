<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 单机模式推送：仅查本地 Swoole\Table，cluster.enable=false 时使用。
 */
class LocalPushDispatcher implements PushDispatcherInterface
{
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
    }

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

    public function broadcastEvent(Server $server, string $event, $data = []): int
    {
        return WebsocketConnectionManager::deliverBroadcastEventLocally($server, $event, $data);
    }
}
