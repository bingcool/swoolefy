<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis Streams 消费组投递（XREADGROUP + XAUTOCLAIM + XACK）。
 *
 * ## 消费组语义（与 Pub/Sub 多进程 SUBSCRIBE 的区别）
 *
 * - 同一 Stream + 同一 Group 内，多个 consumer **竞争**消息，每条 entry 只会被一个 consumer 处理。
 * - 可安全配置 delivery_process_num > 1，不会像 Pub/Sub 那样重复投递。
 *
 * ## 崩溃恢复（PEL + XAUTOCLAIM）
 *
 * 1. XREADGROUP 将消息分配给 consumer，进入 PEL
 * 2. server->push() 成功后 XACK，从 PEL 移除
 * 3. 进程崩溃未 ACK → 消息留在 PEL
 * 4. 重启后 XAUTOCLAIM（idle > stream_claim_idle_ms）将 pending 转给当前 consumer 重试
 *
 * ## 主循环（每次迭代）
 *
 * ```
 * XAUTOCLAIM 回收崩溃 consumer 的 pending
 *   → XREADGROUP BLOCK 拉取新消息（id='>'）
 *   → handler 投递
 *   → 成功则 XACK
 * ```
 *
 * 使用 runDedicated() 独立 Redis 连接，避免与 Worker 短连接的 BRPOP/XREADGROUP 阻塞混用。
 */
class PushStreamConsumer
{
    /**
     * 在独立 Redis 连接上阻塞消费（供 WebsocketPushStreamConsumerProcess 调用）。
     *
     * 正常退出仅当 Redis 连接断开；外层进程负责 sleep 后重连。
     *
     * @param string   $consumerName 组内唯一，如 push-0-12345
     * @param callable $handler      function (string $entryId, string $payload): bool
     *                               返回 true → XACK；false/异常 → 保留在 PEL 等待 reclaim
     */
    public static function run(string $consumerName, callable $handler): void
    {
        self::runControlled($consumerName, $handler, static fn (): bool => true);
    }

    /**
     * 可控消费循环：$shouldContinue 返回 false 时退出（用于优雅停机）。
     *
     * @param callable(): bool $shouldContinue
     */
    public static function runControlled(string $consumerName, callable $handler, callable $shouldContinue): void
    {
        $streamKey = ClusterConfig::pushStreamKeyForServer(ClusterNodeIdentity::getServerId());
        $group = ClusterConfig::pushStreamGroup();

        ClusterRedisClient::runDedicated(static function (ClusterRedisAdapterInterface $redis) use (
            $streamKey,
            $group,
            $consumerName,
            $handler,
            $shouldContinue
        ) {
            self::runControlledOnAdapter($redis, $streamKey, $group, $consumerName, $handler, $shouldContinue);
        });
    }

    /**
     * 停机 drain：处理 PEL 中 pending 直至清空或 deadline，返回处理条数。
     */
    public static function drain(string $consumerName, callable $handler, int $deadline): int
    {
        $streamKey = ClusterConfig::pushStreamKeyForServer(ClusterNodeIdentity::getServerId());
        $group = ClusterConfig::pushStreamGroup();
        $processed = 0;

        ClusterRedisClient::runDedicated(static function (ClusterRedisAdapterInterface $redis) use (
            $streamKey,
            $group,
            $consumerName,
            $handler,
            $deadline,
            &$processed
        ) {
            $processed = self::drainOnAdapter($redis, $streamKey, $group, $consumerName, $handler, $deadline);
        });

        return $processed;
    }

    /**
     * @param callable(): bool $shouldContinue
     */
    public static function runControlledOnAdapterForTest(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        string $consumerName,
        callable $handler,
        callable $shouldContinue
    ): void {
        self::ensureGroup($redis, $streamKey, $group);
        self::runControlledOnAdapter($redis, $streamKey, $group, $consumerName, $handler, $shouldContinue);
    }

    public static function drainOnAdapterForTest(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        string $consumerName,
        callable $handler,
        int $deadline
    ): int {
        self::ensureGroup($redis, $streamKey, $group);

        return self::drainOnAdapter($redis, $streamKey, $group, $consumerName, $handler, $deadline);
    }

    /**
     * @param callable(): bool $shouldContinue
     */
    private static function runControlledOnAdapter(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        string $consumerName,
        callable $handler,
        callable $shouldContinue
    ): void {
        self::ensureGroup($redis, $streamKey, $group);
        $claimCursor = '0-0';

        while ($shouldContinue()) {
            $claimCursor = self::reclaimPending($redis, $streamKey, $group, $consumerName, $handler, $claimCursor);
            if (!$shouldContinue()) {
                break;
            }

            $entries = $redis->xReadGroup(
                $group,
                $consumerName,
                $streamKey,
                ClusterConfig::pushStreamReadCount(),
                self::blockMsForLoop($shouldContinue),
                '>'
            );

            self::handleEntries($redis, $streamKey, $group, $entries, $handler);
        }
    }

    private static function drainOnAdapter(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        string $consumerName,
        callable $handler,
        int $deadline
    ): int {
        $processed = 0;
        $claimCursor = '0-0';

        while (time() < $deadline) {
            $claimCursor = self::reclaimPending(
                $redis,
                $streamKey,
                $group,
                $consumerName,
                $handler,
                $claimCursor,
                0
            );

            $pendingEntries = $redis->xReadGroup(
                $group,
                $consumerName,
                $streamKey,
                ClusterConfig::pushStreamReadCount(),
                200,
                '0'
            );
            if ($pendingEntries !== []) {
                self::handleEntries($redis, $streamKey, $group, $pendingEntries, $handler);
                $processed += count($pendingEntries);
                continue;
            }

            if ((int) $redis->xPendingCount($streamKey, $group) === 0) {
                break;
            }

            usleep(100000);
        }

        return $processed;
    }

    /**
     * XREADGROUP block 时长。
     *
     * 优雅停机启用时上限 500ms，避免长 BLOCK 拖慢感知 shutting_down；
     * 已进入停机则进一步压到 ≤200ms，尽快退出循环交给 drain。
     *
     * @param callable(): bool $shouldContinue
     */
    private static function blockMsForLoop(callable $shouldContinue): int
    {
        $configured = ClusterConfig::pushStreamBlockMs();
        if (!$shouldContinue() || WebsocketShutdownCoordinator::shouldStopConsuming()) {
            return min(200, max(1, $configured));
        }

        // 启用 graceful_shutdown 时缩短常态 block，SIGTERM 前也能更快看到 Table 标志
        if (WebsocketShutdownCoordinator::isEnabled()) {
            return min(500, max(1, $configured));
        }

        return max(1, $configured);
    }

    /**
     * 幂等创建消费组（BUSYGROUP 忽略）。
     *
     * MKSTREAM：Stream 不存在时自动创建，避免消费进程先于首条 XADD 启动时报错。
     */
    public static function ensureGroup(ClusterRedisAdapterInterface $redis, string $streamKey, string $group): void
    {
        $redis->xGroupCreate($streamKey, $group, true);
    }

    /**
     * XAUTOCLAIM 扫描 PEL，回收空闲超过 stream_claim_idle_ms 的消息。
     *
     * @param callable(string, string): bool $handler
     *
     * @return string 下次 XAUTOCLAIM 的 start cursor
     */
    private static function reclaimPending(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        string $consumerName,
        callable $handler,
        string $start,
        ?int $minIdleMs = null
    ): string {
        $minIdle = $minIdleMs ?? ClusterConfig::pushStreamClaimIdleMs();
        $count = ClusterConfig::pushStreamReadCount();
        $cursor = $start;
        $rounds = 0;

        // 限制轮数，避免 pending 过多时长时间占满事件循环
        while ($rounds < 20) {
            [$nextStart, $entries] = $redis->xAutoClaim(
                $streamKey,
                $group,
                $consumerName,
                $minIdle,
                $cursor,
                $count
            );

            if ($entries === []) {
                return $nextStart !== '0-0' ? $nextStart : $cursor;
            }

            self::handleEntries($redis, $streamKey, $group, $entries, $handler);
            $cursor = $nextStart;
            $rounds++;

            if ($nextStart === '0-0') {
                break;
            }
        }

        return $cursor;
    }

    /**
     * 逐条投递并 ACK（供单测 handleEntriesForTest 复用）。
     *
     * @param array<int, array{id: string, payload: string}> $entries
     * @param callable(string, string): bool                   $handler
     */
    public static function handleEntriesForTest(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        array $entries,
        callable $handler
    ): void {
        self::handleEntries($redis, $streamKey, $group, $entries, $handler);
    }

    /**
     * 逐条投递并 ACK。
     *
     * - 空 payload / 无法解析：handler 不调用，直接 ACK 丢弃
     * - handler 返回 true：XACK
     * - handler 返回 false 或抛异常：不 ACK，留在 PEL 供 XAUTOCLAIM 重试
     *
     * @param array<int, array{id: string, payload: string}> $entries
     * @param callable(string, string): bool                   $handler
     */
    private static function handleEntries(
        ClusterRedisAdapterInterface $redis,
        string $streamKey,
        string $group,
        array $entries,
        callable $handler
    ): void {
        foreach ($entries as $entry) {
            $entryId = (string) ($entry['id'] ?? '');
            $payload = (string) ($entry['payload'] ?? '');
            if ($entryId === '' || $payload === '') {
                if ($entryId !== '') {
                    $redis->xAck($streamKey, $group, [$entryId]);
                }
                continue;
            }

            $acked = false;
            try {
                $acked = (bool) $handler($entryId, $payload);
            } catch (\Throwable $throwable) {
                $acked = false;
            }

            if ($acked) {
                $redis->xAck($streamKey, $group, [$entryId]);
            }
        }
    }
}
