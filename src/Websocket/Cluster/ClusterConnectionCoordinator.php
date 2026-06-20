<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 本地 Swoole\Table 与 Redis 全局索引的双写协调器。
 *
 * ## 职责
 *
 * WebSocket 连接生命周期事件发生时，在更新本地 Table 的同时同步 Redis 注册表
 *（`RedisConnectionRegistry`），使集群推送能跨节点定位连接。
 *
 * ## 调用链
 *
 * ```
 * WebsocketConnectionManager / WebsocketServer
 *   → ClusterConnectionCoordinator::onOpen / onClose / onBindUser / ...
 *   → RedisConnectionRegistry::register / unregister / ...
 * ```
 *
 * ## Redis 失败策略（cluster.on_redis_failure）
 *
 * | 策略 | onOpen | 其余操作（close/touch/bind/group） |
 * |------|--------|-------------------------------------|
 * | reject_open（默认） | Redis 失败抛 ClusterRedisException，断开新连接 | 静默失败，仅本地 Table 有效 |
 * | local_only | 全部静默 | 全部静默 |
 *
 * ## touch 节流
 *
 * 本地 Table 的 `last_active_at` 每条消息都会更新；写 Redis 按 `touch_interval` 节流，
 * 避免高频消息导致 HSET + EXPIRE + ZADD 风暴。节流状态保存在 Worker 进程静态变量中。
 *
 * @see RedisConnectionRegistry
 * @see ClusterConfig::onRedisFailure()
 * @see ClusterConfig::touchInterval()
 */
class ClusterConnectionCoordinator
{
    /** @var array<string, int> conn_id => 上次写入 Redis 的 touch 时间戳（Worker 内节流） */
    private static array $lastRedisTouchAt = [];

    /**
     * 连接建立：向 Redis 注册 conn 及索引。
     *
     * 若未传 conn_id，由 ClusterNodeIdentity::makeConnId($fd) 生成。
     * 此为 strict 操作：Redis 失败且 on_redis_failure=reject_open 时抛异常。
     */
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

        self::run('register', static function () use ($connId, $payload) {
            RedisConnectionRegistry::register($connId, $payload);
        });

        $lastActiveAt = (int) ($payload['last_active_at'] ?? time());
        self::$lastRedisTouchAt[$connId] = $lastActiveAt;
    }

    /** 连接关闭：从 Redis 注销 conn 及全部反向索引 */
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

    /** 用户绑定/换绑：同步 user Set 索引 */
    public static function onBindUser(string $connId, string $userId, string $oldUserId = ''): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

        self::run('bindUser', static function () use ($connId, $userId, $oldUserId) {
            RedisConnectionRegistry::bindUser($connId, $userId, $oldUserId);
        }, false);
    }

    /** 加入小组：同步 group Set 索引 */
    public static function onJoinGroup(string $connId, string $group, string $groupsJson): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '' || $group === '') {
            return;
        }

        self::run('joinGroup', static function () use ($connId, $group, $groupsJson) {
            RedisConnectionRegistry::joinGroup($connId, $group, $groupsJson);
        }, false);
    }

    /** 离开小组：从 group Set 移除 */
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
     * 刷新 Redis 全局索引心跳（经 touch_interval 节流）。
     *
     * 本地 Table 仍每条消息更新 last_active_at；仅当距上次 Redis touch 超过
     * touch_interval 秒时才调用 RedisConnectionRegistry::touch()。
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

    /**
     * 清理心跳超时的僵尸连接索引（委托 RedisConnectionRegistry::cleanupExpired）。
     *
     * Redis 异常时返回 0，不影响 Worker 主流程。
     */
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

    /**
     * 执行 Redis 同步操作并处理异常。
     *
     * @param bool $strict true 时 Redis 失败且 reject_open 策略下抛 ClusterRedisException
     */
    private static function run(string $action, callable $callback, bool $strict = true): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
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
