<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Offline\OfflineMessageCoordinator;
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
        $userOutcomes = [];
        $fds = array_unique(WebsocketConnectionManager::getFdsByGroup($group));
        foreach ($fds as $fd) {
            $outcome = WebsocketConnectionManager::deliverEventToFdLocallyDetailed($server, (int) $fd, $event, $data);
            self::recordLocalOutcome($userOutcomes, (int) $fd, $outcome);
            if ($outcome === 'delivered') {
                $count++;
            }
        }

        if ($fds === []) {
            OfflineMessageCoordinator::maybeStoreOfflineAfterGroupPush(
                $group,
                $event,
                $data,
                self::emptyFanoutResult()
            );
        } else {
            OfflineMessageCoordinator::maybeStoreOfflineAfterLocalFanout($event, $data, $userOutcomes, 'pushToGroup');
        }

        return $count;
    }

    /** 遍历本节点全部连接广播 */
    public function broadcastEvent(Server $server, string $event, $data = []): int
    {
        $count = 0;
        $userOutcomes = [];
        if (TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
            foreach (TableManager::getTable(WebsocketConnectionManager::TABLE_CONNECTIONS) as $row) {
                $fd = (int) ($row['fd'] ?? 0);
                if ($fd <= 0) {
                    continue;
                }

                $outcome = WebsocketConnectionManager::deliverEventToFdLocallyDetailed($server, $fd, $event, $data);
                $userId = trim((string) ($row['user_id'] ?? ''));
                if ($userId !== '') {
                    $userOutcomes[$userId][] = $outcome;
                }
                if ($outcome === 'delivered') {
                    $count++;
                }
            }
        }

        if ($userOutcomes === []) {
            OfflineMessageCoordinator::maybeStoreOfflineAfterBroadcastPush(
                $event,
                $data,
                self::emptyFanoutResult()
            );
        } else {
            OfflineMessageCoordinator::maybeStoreOfflineAfterLocalFanout($event, $data, $userOutcomes, 'broadcast');
        }

        return $count;
    }

    /**
     * @param array<string, string[]> $userOutcomes
     */
    private static function recordLocalOutcome(array &$userOutcomes, int $fd, string $outcome): void
    {
        $connection = WebsocketConnectionManager::getConnection($fd);
        if (!is_array($connection)) {
            return;
        }

        $userId = trim((string) ($connection['user_id'] ?? ''));
        if ($userId === '') {
            return;
        }

        $userOutcomes[$userId][] = $outcome;
    }

    private static function emptyFanoutResult(): PushFanoutResult
    {
        return new PushFanoutResult();
    }
}
