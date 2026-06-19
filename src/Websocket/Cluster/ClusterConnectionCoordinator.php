<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 本地 Table 与 Redis 全局索引的双写协调器。
 * onOpen 失败且 on_redis_failure=reject_open 时会抛异常，由 WebsocketServer 断开连接。
 */
class ClusterConnectionCoordinator
{
    /** @var array<string, int> conn_id => 上次写入 Redis 的 touch 时间戳（Worker 内节流） */
    private static array $lastRedisTouchAt = [];

    public static function onOpen(int $fd, array $connection): void
    {
        if (!ClusterConfig::isEnabled()) {
            return;
        }

        $connId = (string) ($connection['conn_id'] ?? ClusterNodeIdentity::makeConnId($fd));
        $payload = array_merge($connection, [
            'conn_id' => $connId,
            'server_id' => ClusterNodeIdentity::getServerId(),
            'fd' => $fd,
        ]);

        // 注册是严格操作：Redis 失败可拒绝新连接
        self::run('register', static function () use ($connId, $payload) {
            RedisConnectionRegistry::register($connId, $payload);
        });

        $lastActiveAt = (int) ($payload['last_active_at'] ?? time());
        self::$lastRedisTouchAt[$connId] = $lastActiveAt;
    }

    public static function onClose(string $connId): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

        unset(self::$lastRedisTouchAt[$connId]);

        self::run('unregister', static function () use ($connId) {
            RedisConnectionRegistry::unregister($connId);
        }, false);
    }

    public static function onBindUser(string $connId, string $userId, string $oldUserId = ''): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

        self::run('bindUser', static function () use ($connId, $userId, $oldUserId) {
            RedisConnectionRegistry::bindUser($connId, $userId, $oldUserId);
        }, false);
    }

    public static function onJoinGroup(string $connId, string $group, string $groupsJson): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '' || $group === '') {
            return;
        }

        self::run('joinGroup', static function () use ($connId, $group, $groupsJson) {
            RedisConnectionRegistry::joinGroup($connId, $group, $groupsJson);
        }, false);
    }

    public static function onLeaveGroup(string $connId, string $group, string $groupsJson): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '' || $group === '') {
            return;
        }

        self::run('leaveGroup', static function () use ($connId, $group, $groupsJson) {
            RedisConnectionRegistry::leaveGroup($connId, $group, $groupsJson);
        }, false);
    }

    /**
     * 刷新 Redis 全局索引心跳。
     *
     * 本地 Table 的 last_active_at 每条消息都会更新；写 Redis 按 touch_interval 节流，
     * 避免高频消息导致 HSET+EXPIRE+ZADD 风暴。
     */
    public static function onTouch(string $connId, int $lastActiveAt): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

        $interval = ClusterConfig::touchInterval();
        $lastRedisTouch = self::$lastRedisTouchAt[$connId] ?? 0;
        if ($lastActiveAt - $lastRedisTouch < $interval) {
            return;
        }

        self::$lastRedisTouchAt[$connId] = $lastActiveAt;

        self::run('touch', static function () use ($connId, $lastActiveAt) {
            RedisConnectionRegistry::touch($connId, $lastActiveAt);
        }, false);
    }

    public static function cleanupExpired(int $idleTimeout): int
    {
        if (!ClusterConfig::isEnabled()) {
            return 0;
        }

        try {
            return RedisConnectionRegistry::cleanupExpired($idleTimeout);
        } catch (\Throwable $throwable) {
            return 0;
        }
    }

    /** 单测重置 touch 节流状态 */
    public static function resetTouchThrottle(): void
    {
        self::$lastRedisTouchAt = [];
    }

    private static function run(string $action, callable $callback, bool $strict = true): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            // strict=true 仅用于 onOpen；其余生命周期操作失败时静默（local_only 策略）
            if ($strict && ClusterConfig::onRedisFailure() === 'reject_open') {
                throw new ClusterRedisException(
                    sprintf('WebSocket cluster %s failed: %s', $action, $throwable->getMessage()),
                    0,
                    $throwable
                );
            }
        }
    }
}
