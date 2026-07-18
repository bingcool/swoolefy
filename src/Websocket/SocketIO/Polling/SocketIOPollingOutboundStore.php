<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoole\Coroutine\Channel;
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;

/**
 * Engine.IO long-polling 出站包队列。
 *
 * ## 职责
 *
 * 服务端要向 polling 客户端推送 Engine.IO 包（如 `2` ping、`42[...]` 事件）时，
 * 不能依赖 WebSocket fd push，而是写入本队列；客户端下一次 GET poll 时取走。
 *
 * ## 两种后端
 *
 * | 模式 | 存储 | long-poll 唤醒 |
 * |------|------|----------------|
 * | memory（单 Worker） | 静态数组 `$memoryQueues` | 协程 Channel pop |
 * | shared（多 Worker） | Redis List `{prefix}poll:out:{sid}` | BRPOP 阻塞 |
 *
 * ## 典型时序（Redis 模式）
 *
 * ```
 * Worker-A: 握手 bindSid → （暂无出站）
 * Worker-B: GET poll?sid=xxx → blockingPop(BRPOP 25s)
 * Worker-C: pushToFd → enqueue(RPUSH) → BRPOP 返回 → Worker-B 响应 HTTP 200 + 包体
 * ```
 *
 * 因此 **无需 Nginx sticky**：sid 在 SessionRegistry 的 Table 中共享，出站在 Redis 中共享。
 *
 * ## Redis 键
 *
 * - 键名：`{redisKeyPrefix}poll:out:{sid}`（见 redisQueueKey()）
 * - enqueue：RPUSH + LTRIM（保留最新 outboundMaxLen 条）+ EXPIRE
 * - drain：循环 LPOP 直到空（非阻塞，用于握手后立即 flush 或 POST 后合并响应）
 * - blockingPop：BRPOP（独立连接，见 SocketIOPollingRedisClient）
 *
 * @see SocketIOPollingConfig::usesSharedStore()
 * @see SocketIOSessionManager::enqueueOutbound()
 * @see SocketIOSessionManager::waitOutbound()
 */
class SocketIOPollingOutboundStore
{
    /** @var array<string, string[]> memory 模式：sid → 待发送 Engine.IO 包列表 */
    private static array $memoryQueues = [];

    /** @var array<string, Channel> memory 模式：long-poll 协程在此 Channel 上阻塞等待 enqueue 信号 */
    private static array $memoryWaitChannels = [];

    /**
     * 写入一条出站 Engine.IO 包。
     *
     * @param string $sid   Engine.IO session id
     * @param string $packet 已编码的包，如 `42["chat.message",{"msg":"hi"}]`
     * @return bool 参数非法或 Redis 异常时 false
     */
    public static function enqueue(string $sid, string $packet): bool
    {
        if ($sid === '' || $packet === '') {
            return false;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            self::$memoryQueues[$sid] ??= [];
            self::$memoryQueues[$sid][] = $packet;
            $maxLen = SocketIOPollingConfig::outboundMaxLen();
            if (count(self::$memoryQueues[$sid]) > $maxLen) {
                self::$memoryQueues[$sid] = array_slice(self::$memoryQueues[$sid], -$maxLen);
            }
            // 唤醒正在 memoryWaitChannel 上 long-poll 的协程（非阻塞：无 waiter 时丢弃多余信号）
            self::memoryWaitChannel($sid)->push(true, 0.001);

            return true;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $packet): void {
                $key = self::redisQueueKey($sid);
                $redis->rPush($key, $packet);
                // 只保留队列尾部 N 条，避免慢客户端堆积
                $redis->lTrim($key, -SocketIOPollingConfig::outboundMaxLen(), -1);
                $redis->expire($key, SocketIOPollingConfig::sessionTtl());
            });

            return true;
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * 非阻塞取走 sid 下全部待发包并清空队列。
     *
     * 用于：poll GET 返回前合并已有包、POST 处理完后附带响应等。
     *
     * @return string[] Engine.IO 包列表，无数据时 []
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
     * 阻塞等待一条出站包（long-polling GET 核心路径）。
     *
     * - memory：Channel::pop(timeout) 后 drain 取第一条
     * - redis：独立连接 BRPOP，超时返回 null（Engine.IO 正常空 poll）
     *
     * @param int $timeoutSec 最长阻塞秒数，通常来自 socketio.poll_timeout
     * @return string|null 单条 Engine.IO 包，超时或无数据 null
     */
    public static function blockingPop(string $sid, int $timeoutSec): ?string
    {
        if ($sid === '' || $timeoutSec <= 0) {
            return null;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            self::memoryWaitChannel($sid)->pop((float) $timeoutSec);

            if (!isset(self::$memoryQueues[$sid]) || self::$memoryQueues[$sid] === []) {
                return null;
            }

            return array_shift(self::$memoryQueues[$sid]);
        }

        $key = self::redisQueueKey($sid);
        try {
            $result = null;
            // BRPOP 必须使用独立 Redis 连接，不可与 execute() 复用
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

    /**
     * 会话结束时清理出站队列（memory 数组 + Redis DEL）。
     *
     * 也可依赖 session_ttl 自动过期；显式 clear 避免短 sid 复用前读到脏数据。
     */
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

    /** Redis List 完整键名，供 SessionRegistry::remove 联动删除 */
    public static function redisQueueKey(string $sid): string
    {
        return SocketIOPollingConfig::redisKeyPrefix() . 'poll:out:' . $sid;
    }

    /** 单测 teardown：清空 memory 静态状态 */
    public static function resetForTest(): void
    {
        self::$memoryQueues = [];
        self::$memoryWaitChannels = [];
    }

    /** memory 模式下为 sid 懒创建唤醒 Channel（容量 8，仅作信号量） */
    private static function memoryWaitChannel(string $sid): Channel
    {
        if (!isset(self::$memoryWaitChannels[$sid])) {
            self::$memoryWaitChannels[$sid] = new Channel(8);
        }

        return self::$memoryWaitChannels[$sid];
    }

    /** 非阻塞 Redis 命令走 EventApp / 协程缓存连接 */
    private static function executeRedis(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }
}
