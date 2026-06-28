<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoole\Coroutine\Channel;
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;

/**
 * polling 出站包队列：memory（单 Worker）或 Redis List（跨 Worker / 节点）。
 *
 * Redis 模式 long-poll 使用 BRPOP 阻塞，push 方 RPUSH 即可唤醒任意 Worker 上的 poll 请求。
 */
class SocketIOPollingOutboundStore
{
    /** @var array<string, string[]> 单 Worker memory 模式 */
    private static array $memoryQueues = [];

    /** @var array<string, Channel> memory 模式唤醒 long-poll */
    private static array $memoryWaitChannels = [];

    public static function enqueue(string $sid, string $packet): bool
    {
        if ($sid === '' || $packet === '') {
            return false;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            self::$memoryQueues[$sid] ??= [];
            self::$memoryQueues[$sid][] = $packet;
            self::memoryWaitChannel($sid)->push(true);

            return true;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $packet): void {
                $key = self::redisQueueKey($sid);
                $redis->rPush($key, $packet);
                $redis->lTrim($key, -SocketIOPollingConfig::outboundMaxLen(), -1);
                $redis->expire($key, SocketIOPollingConfig::sessionTtl());
            });

            return true;
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * @return string[]
     */
    public static function drain(string $sid): array
    {
        if ($sid === '') {
            return [];
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            if (!isset(self::$memoryQueues[$sid]) || self::$memoryQueues[$sid] === []) {
                return [];
            }

            $packets = self::$memoryQueues[$sid];
            self::$memoryQueues[$sid] = [];

            return $packets;
        }

        try {
            return self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid): array {
                $key = self::redisQueueKey($sid);
                $packets = [];
                while (true) {
                    $item = $redis->lPop($key);
                    if ($item === null || $item === false || $item === '') {
                        break;
                    }
                    $packets[] = (string) $item;
                }

                return $packets;
            });
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    /**
     * 阻塞等待一条出站包（long-poll）；超时返回 null。
     */
    public static function blockingPop(string $sid, int $timeoutSec): ?string
    {
        if ($sid === '' || $timeoutSec <= 0) {
            return null;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            self::memoryWaitChannel($sid)->pop((float) $timeoutSec);
            $packets = self::drain($sid);

            return $packets[0] ?? null;
        }

        $key = self::redisQueueKey($sid);
        try {
            $result = null;
            SocketIOPollingRedisClient::runDedicated(
                static function (ClusterRedisAdapterInterface $redis) use ($key, $timeoutSec, &$result): void {
                    $result = $redis->brPop($key, $timeoutSec);
                }
            );

            return $result;
        } catch (\Throwable $throwable) {
            return null;
        }
    }

    public static function clear(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        unset(self::$memoryQueues[$sid], self::$memoryWaitChannels[$sid]);

        if (!SocketIOPollingConfig::usesSharedStore()) {
            return;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid): void {
                $redis->del(self::redisQueueKey($sid));
            });
        } catch (\Throwable $throwable) {
        }
    }

    public static function redisQueueKey(string $sid): string
    {
        return SocketIOPollingConfig::redisKeyPrefix() . 'poll:out:' . $sid;
    }

    public static function resetForTest(): void
    {
        self::$memoryQueues = [];
        self::$memoryWaitChannels = [];
    }

    private static function memoryWaitChannel(string $sid): Channel
    {
        if (!isset(self::$memoryWaitChannels[$sid])) {
            self::$memoryWaitChannels[$sid] = new Channel(8);
        }

        return self::$memoryWaitChannels[$sid];
    }

    private static function executeRedis(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }
}
