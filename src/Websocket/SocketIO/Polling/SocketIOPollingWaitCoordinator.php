<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Websocket\Cluster\ClusterRedisClient;

/**
 * long-poll GET 的「单 sid 单 waiter」协调器。
 *
 * engine.io-client 会并发 2 条 GET；若都进入 BRPOP，会占满 Worker 并阻塞 POST `40` connect。
 * 本类保证同一 sid 同时最多一条 GET 进入短阻塞等待，其余立即空响应。
 *
 * | 模式 | 实现 |
 * |------|------|
 * | memory（单 Worker） | 进程静态数组 |
 * | shared（多 Worker） | Redis SET NX EX（键 poll:wait:{sid}，TTL = waitSec + 1） |
 *
 * @see SocketIOSessionManager::waitOutbound()
 */
class SocketIOPollingWaitCoordinator
{
    /** @var array<string, true> memory 模式：sid → 正在 wait */
    private static array $memoryWaiters = [];

    /**
     * 尝试成为 sid 的唯一 long-poll 等待者。
     *
     * @param int $waitSec 即将 BRPOP 的秒数（锁 TTL 略大于此值，防止 Worker 崩溃死锁）
     */
    public static function tryAcquire(string $sid, int $waitSec): bool
    {
        if ($sid === '' || $waitSec <= 0) {
            return false;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            if (isset(self::$memoryWaiters[$sid])) {
                return false;
            }

            self::$memoryWaiters[$sid] = true;

            return true;
        }

        try {
            $ttl = $waitSec + 1;

            return (bool) ClusterRedisClient::execute(
                static fn ($redis) => $redis->setNxEx(self::redisWaitKey($sid), $ttl, '1')
            );
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /** 释放 waiter 锁；须在 waitOutbound finally 中调用 */
    public static function release(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            unset(self::$memoryWaiters[$sid]);

            return;
        }

        try {
            ClusterRedisClient::execute(static fn ($redis) => $redis->del(self::redisWaitKey($sid)));
        } catch (\Throwable $throwable) {
        }
    }

    public static function resetForTest(): void
    {
        self::$memoryWaiters = [];
    }

    private static function redisWaitKey(string $sid): string
    {
        return SocketIOPollingConfig::redisKeyPrefix() . 'poll:wait:' . $sid;
    }
}
