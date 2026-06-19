<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis 全局连接注册表（跨节点共享）：
 * - conn:{conn_id}  Hash  连接元数据 + TTL
 * - user:{user_id}  Set   用户下所有 conn_id
 * - group:{group}     Set   小组下所有 conn_id
 * - node:{server_id} Set  节点下所有 conn_id
 * - nodes           Set   在线节点列表
 * - alive           ZSET  心跳时间，用于宕机清理
 */
class RedisConnectionRegistry
{
    public static function register(string $connId, array $connection): void
    {
        self::execute(function ($redis) use ($connId, $connection) {
            $ttl = ClusterConfig::connTtl();
            $serverId = (string) $connection['server_id'];
            $userId = (string) ($connection['user_id'] ?? '');
            $lastActiveAt = (int) ($connection['last_active_at'] ?? time());

            $redis->hMSet(self::connKey($connId), [
                'server_id' => $serverId,
                'fd' => (int) ($connection['fd'] ?? 0),
                'worker_id' => (int) ($connection['worker_id'] ?? 0),
                'user_id' => $userId,
                'groups' => (string) ($connection['groups'] ?? ''),
                'is_socketio' => (int) ($connection['is_socketio'] ?? 0),
                'remote_addr' => (string) ($connection['remote_addr'] ?? ''),
                'connected_at' => (int) ($connection['connected_at'] ?? time()),
                'last_active_at' => $lastActiveAt,
            ]);
            $redis->expire(self::connKey($connId), $ttl);
            $redis->sAdd(self::nodesKey(), $serverId);
            $redis->sAdd(self::nodeConnsKey($serverId), $connId);
            // alive ZSET 供 worker 0 定时清理无 onClose 的僵尸索引（节点宕机场景）
            $redis->zAdd(self::aliveKey(), $lastActiveAt, $connId);

            if ($userId !== '') {
                $redis->sAdd(self::userKey($userId), $connId);
            }
        });
    }

    public static function unregister(string $connId): void
    {
        self::execute(function ($redis) use ($connId) {
            $meta = $redis->hGetAll(self::connKey($connId));
            if (!is_array($meta) || empty($meta)) {
                $redis->zRem(self::aliveKey(), $connId);
                return;
            }

            $serverId = (string) ($meta['server_id'] ?? '');
            $userId = (string) ($meta['user_id'] ?? '');
            $groups = self::decodeGroups((string) ($meta['groups'] ?? ''));

            $redis->del(self::connKey($connId));
            $redis->zRem(self::aliveKey(), $connId);

            if ($userId !== '') {
                $redis->sRem(self::userKey($userId), $connId);
            }

            foreach ($groups as $group) {
                $redis->sRem(self::groupKey($group), $connId);
            }

            if ($serverId !== '') {
                $redis->sRem(self::nodeConnsKey($serverId), $connId);
                // 节点无连接时从 nodes 集合移除，broadcast 不再向该节点发消息
                if ((int) $redis->sCard(self::nodeConnsKey($serverId)) === 0) {
                    $redis->sRem(self::nodesKey(), $serverId);
                }
            }
        });
    }

    public static function bindUser(string $connId, string $userId, string $oldUserId = ''): void
    {
        self::execute(function ($redis) use ($connId, $userId, $oldUserId) {
            if ($oldUserId !== '' && $oldUserId !== $userId) {
                $redis->sRem(self::userKey($oldUserId), $connId);
            }

            $redis->hSet(self::connKey($connId), 'user_id', $userId);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());

            if ($userId !== '') {
                $redis->sAdd(self::userKey($userId), $connId);
            }
        });
    }

    public static function joinGroup(string $connId, string $group, string $groupsJson): void
    {
        self::execute(function ($redis) use ($connId, $group, $groupsJson) {
            $redis->hSet(self::connKey($connId), 'groups', $groupsJson);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->sAdd(self::groupKey($group), $connId);
        });
    }

    public static function leaveGroup(string $connId, string $group, string $groupsJson): void
    {
        self::execute(function ($redis) use ($connId, $group, $groupsJson) {
            $redis->hSet(self::connKey($connId), 'groups', $groupsJson);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->sRem(self::groupKey($group), $connId);
        });
    }

    public static function touch(string $connId, int $lastActiveAt): void
    {
        self::execute(function ($redis) use ($connId, $lastActiveAt) {
            $redis->hSet(self::connKey($connId), 'last_active_at', $lastActiveAt);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->zAdd(self::aliveKey(), $lastActiveAt, $connId);
        });
    }

    public static function getConnIdsByUser(string $userId): array
    {
        return self::execute(function ($redis) use ($userId) {
            $items = $redis->sMembers(self::userKey($userId));

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    public static function getConnIdsByGroup(string $group): array
    {
        return self::execute(function ($redis) use ($group) {
            $items = $redis->sMembers(self::groupKey($group));

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    public static function getConnectionMeta(string $connId): ?array
    {
        return self::execute(function ($redis) use ($connId) {
            $meta = $redis->hGetAll(self::connKey($connId));
            if (!is_array($meta) || empty($meta)) {
                return null;
            }

            return $meta;
        });
    }

    public static function getAllNodeIds(): array
    {
        return self::execute(function ($redis) {
            $items = $redis->sMembers(self::nodesKey());

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    public static function cleanupExpired(int $idleTimeout): int
    {
        return self::execute(function ($redis) use ($idleTimeout) {
            $deadline = time() - $idleTimeout;
            // 扫描心跳超时的 conn_id，批量清理 user/group/node 索引
            $connIds = $redis->zRangeByScore(self::aliveKey(), '0', (string) $deadline);
            if (!is_array($connIds) || empty($connIds)) {
                return 0;
            }

            $removed = 0;
            foreach ($connIds as $connId) {
                if (!is_string($connId) || $connId === '') {
                    continue;
                }

                $meta = $redis->hGetAll(self::connKey($connId));
                if (is_array($meta) && !empty($meta)) {
                    $serverId = (string) ($meta['server_id'] ?? '');
                    $userId = (string) ($meta['user_id'] ?? '');
                    $groups = self::decodeGroups((string) ($meta['groups'] ?? ''));

                    if ($userId !== '') {
                        $redis->sRem(self::userKey($userId), $connId);
                    }
                    foreach ($groups as $group) {
                        $redis->sRem(self::groupKey($group), $connId);
                    }
                    if ($serverId !== '') {
                        $redis->sRem(self::nodeConnsKey($serverId), $connId);
                        if ((int) $redis->sCard(self::nodeConnsKey($serverId)) === 0) {
                            $redis->sRem(self::nodesKey(), $serverId);
                        }
                    }
                }

                $redis->del(self::connKey($connId));
                $redis->zRem(self::aliveKey(), $connId);
                $removed++;
            }

            return $removed;
        });
    }

    public static function publish(string $serverId, array $message): bool
    {
        // 精准扇出：每节点独立频道 ws:push:{app}:{server_id}
        // ExternalPushPublisher 与 Worker 跨节点推送均走此方法
        return (bool) self::execute(function ($redis) use ($serverId, $message) {
            return $redis->publish(ClusterConfig::pushChannelForServer($serverId), PushMessage::encode($message));
        });
    }

    private static function execute(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }

    private static function connKey(string $connId): string
    {
        return ClusterConfig::keyPrefix() . 'conn:' . $connId;
    }

    private static function userKey(string $userId): string
    {
        return ClusterConfig::keyPrefix() . 'user:' . $userId;
    }

    private static function groupKey(string $group): string
    {
        return ClusterConfig::keyPrefix() . 'group:' . $group;
    }

    private static function nodeConnsKey(string $serverId): string
    {
        return ClusterConfig::keyPrefix() . 'node:' . $serverId;
    }

    private static function nodesKey(): string
    {
        return ClusterConfig::keyPrefix() . 'nodes';
    }

    private static function aliveKey(): string
    {
        return ClusterConfig::keyPrefix() . 'alive';
    }

    private static function decodeGroups(string $groups): array
    {
        $items = $groups === '' ? [] : json_decode($groups, true);

        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }
}
