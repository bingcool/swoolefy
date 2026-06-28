<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;

/**
 * polling sid → virtual_fd 注册表（跨 Worker 共享）。
 *
 * - Swoole Table：同节点所有 Worker 可读 sid 索引
 * - Redis Hash（cluster / shared_store）：跨节点校验与会话 TTL
 */
class SocketIOPollingSessionRegistry
{
    public const TABLE_POLLING_SID = 'table_websocket_polling_sid';

    public static function register(string $sid, int $virtualFd, string $connId = ''): void
    {
        if ($sid === '' || $virtualFd <= 0) {
            return;
        }

        $now = time();
        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            TableManager::set(self::TABLE_POLLING_SID, $sid, [
                'virtual_fd' => $virtualFd,
                'last_active_at' => $now,
            ]);
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $virtualFd, $connId, $now): void {
            $key = self::redisSessionKey($sid);
            $redis->hMSet($key, [
                'sid' => $sid,
                'virtual_fd' => (string) $virtualFd,
                'server_id' => ClusterNodeIdentity::getServerId(),
                'conn_id' => $connId,
                'created_at' => (string) $now,
                'last_active_at' => (string) $now,
            ]);
            $redis->expire($key, SocketIOPollingConfig::sessionTtl());
        });
    }

    public static function exists(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID) && TableManager::exist(self::TABLE_POLLING_SID, $sid)) {
            return true;
        }

        if (!self::shouldMirrorRedis()) {
            return false;
        }

        try {
            return (bool) self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid) {
                return $redis->exists(self::redisSessionKey($sid));
            });
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    public static function getVirtualFd(string $sid): int
    {
        if ($sid === '') {
            return 0;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            $row = TableManager::get(self::TABLE_POLLING_SID, $sid);
            if (is_array($row) && !empty($row)) {
                return (int) ($row['virtual_fd'] ?? 0);
            }
        }

        if (!self::shouldMirrorRedis()) {
            return 0;
        }

        try {
            $meta = self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid) {
                return $redis->hGetAll(self::redisSessionKey($sid));
            });
            if (is_array($meta) && !empty($meta['virtual_fd'])) {
                return (int) $meta['virtual_fd'];
            }
        } catch (\Throwable $throwable) {
            return 0;
        }

        return 0;
    }

    public static function touch(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        $now = time();
        if (TableManager::isExistTable(self::TABLE_POLLING_SID) && TableManager::exist(self::TABLE_POLLING_SID, $sid)) {
            $row = TableManager::get(self::TABLE_POLLING_SID, $sid);
            if (is_array($row)) {
                $row['last_active_at'] = $now;
                TableManager::set(self::TABLE_POLLING_SID, $sid, $row);
            }
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $now): void {
                $key = self::redisSessionKey($sid);
                $redis->hSet($key, 'last_active_at', (string) $now);
                $redis->expire($key, SocketIOPollingConfig::sessionTtl());
            });
        } catch (\Throwable $throwable) {
            // touch 失败不影响主流程
        }
    }

    public static function remove(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            TableManager::del(self::TABLE_POLLING_SID, $sid);
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid): void {
                $redis->del(self::redisSessionKey($sid));
                $redis->del(SocketIOPollingOutboundStore::redisQueueKey($sid));
            });
        } catch (\Throwable $throwable) {
            // 清理失败可依赖 TTL
        }
    }

    public static function tableDefinitions(int $size = 65536): array
    {
        return [
            self::TABLE_POLLING_SID => [
                'size' => $size,
                'fields' => [
                    ['virtual_fd', 'int', 8],
                    ['last_active_at', 'int', 8],
                ],
            ],
            SocketIOSessionManager::TABLE_POLLING_META => [
                'size' => 1,
                'fields' => [
                    ['seq', 'int', 8],
                ],
            ],
        ];
    }

    public static function resetForTest(): void
    {
        if (!TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            return;
        }

        foreach (TableManager::getTableKeys(self::TABLE_POLLING_SID) as $sid) {
            TableManager::del(self::TABLE_POLLING_SID, (string) $sid);
        }
    }

    private static function shouldMirrorRedis(): bool
    {
        return SocketIOPollingConfig::usesSharedStore();
    }

    private static function redisSessionKey(string $sid): string
    {
        return SocketIOPollingConfig::redisKeyPrefix() . 'poll:sid:' . $sid;
    }

    private static function executeRedis(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }
}
