<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 本地 Table 与 Redis 全局索引的双写协调器。
 * onOpen 失败且 on_redis_failure=reject_open 时会抛异常，由 WebsocketServer 断开连接。
 */
class ClusterConnectionCoordinator
{
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
    }

    public static function onClose(string $connId): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

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

    public static function onJoinRoom(string $connId, string $room, string $roomsJson): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '' || $room === '') {
            return;
        }

        self::run('joinRoom', static function () use ($connId, $room, $roomsJson) {
            RedisConnectionRegistry::joinRoom($connId, $room, $roomsJson);
        }, false);
    }

    public static function onLeaveRoom(string $connId, string $room, string $roomsJson): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '' || $room === '') {
            return;
        }

        self::run('leaveRoom', static function () use ($connId, $room, $roomsJson) {
            RedisConnectionRegistry::leaveRoom($connId, $room, $roomsJson);
        }, false);
    }

    public static function onTouch(string $connId, int $lastActiveAt): void
    {
        if (!ClusterConfig::isEnabled() || $connId === '') {
            return;
        }

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
