<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Table\TableManager;

/**
 * WebSocket 优雅停机协调（Master + Worker + 推送消费进程共享 Table 标志）。
 *
 * SIGTERM / cli stop 流程：
 * 1. Master 收到 SIGTERM → Swoole 内置 shutdown → BeforeShutdown 回调
 * 2. 置 shutting_down + 停止 accept（Swoole 已处理）+ 等待 Stream PEL drain
 * 3. open / handshake / message 拒绝新连接与新业务帧（1008 / 503）
 * 4. Stream / PubSub 推送消费进程停止拉取并 drain（PEL 或本地 List）
 * 5. 自定义进程 SIGTERM → gracefulShutdownDrain → 退出
 * 6. WorkerStop 主动 disconnect 剩余 fd；Swoole 按 max_wait_time 等待后退出
 *
 * 注意：勿对 Master 重复 Process::signal(SIGTERM)，会与 Swoole 冲突。
 * StopCmd 强制 kill 超时须 ≥ recommendedStopTimeout()，否则会半截杀掉 drain。
 */
class WebsocketShutdownCoordinator
{
    public const TABLE = 'table_websocket_shutdown';

    private const ROW = '_global';

    private const FIELD_SHUTTING_DOWN = 'shutting_down';

    private const FIELD_STARTED_AT = 'started_at';

    /** @var bool|null 单测内存标志，绕过 Table */
    private static ?bool $testShuttingDown = null;

    public static function tableDefinitions(): array
    {
        return [
            self::TABLE => [
                'size' => 1,
                'fields' => [
                    [self::FIELD_SHUTTING_DOWN, 'int', 1],
                    [self::FIELD_STARTED_AT, 'int', 8],
                ],
            ],
        ];
    }

    public static function settings(): array
    {
        $settings = ClusterConfig::websocket()['graceful_shutdown'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    public static function isEnabled(): bool
    {
        return !empty(self::settings()['enable']);
    }

    public static function drainTimeout(): int
    {
        return max(1, (int) (self::settings()['drain_timeout'] ?? 30));
    }

    /**
     * StopCmd 建议等待上限：drain_timeout + max_wait_time + 缓冲。
     * 保证 Master PEL 排水与 Worker 收尾不被 CLI SIGKILL 提前打断。
     */
    public static function recommendedStopTimeout(int $maxWaitTime = 10): int
    {
        return self::drainTimeout() + max(1, $maxWaitTime) + 5;
    }

    /**
     * StopCmd 建议开始强制 kill 的时间：略大于 drain_timeout，留给消费进程 SIGTERM drain。
     */
    public static function recommendedForceKillTimeout(int $maxWaitTime = 10): int
    {
        return self::drainTimeout() + max(1, (int) ceil($maxWaitTime / 2));
    }

    public static function rejectReason(): string
    {
        $reason = (string) (self::settings()['reject_reason'] ?? 'server shutting down');

        return $reason !== '' ? $reason : 'server shutting down';
    }

    /**
     * 在 Server::start() 之前注册 BeforeShutdown（Swoole 生命周期事件须 start 前绑定）。
     *
     * `kill -15` / `cli.php stop` 走 Swoole Master 内置 SIGTERM → 本回调执行 drain。
     */
    public static function registerServerShutdownHook(\Swoole\Server $server): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $server->on('BeforeShutdown', static function (\Swoole\Server $server): void {
            self::onBeforeShutdown($server);
        });
    }

    /**
     * Master Start 后注册 SIGINT（前台 Ctrl+C）。
     *
     * SIGTERM 由 Swoole Master 占用，此处 deliberately 不注册。
     */
    public static function installForegroundSignalHandler(\Swoole\Server $server): void
    {
        if (!self::isEnabled() || !extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        \Swoole\Process::signal(SIGINT, static function () use ($server): void {
            self::beginShutdown($server);
        });
    }

    /**
     * Swoole 进入关机流程时调用（SIGTERM 路径）；不再调用 server->shutdown()。
     */
    public static function onBeforeShutdown(\Swoole\Server $server): void
    {
        unset($server);
        if (!self::isEnabled() || self::isShuttingDown()) {
            return;
        }

        self::markShuttingDown();
        self::waitForStreamPelDrain();
    }

    /**
     * 主动触发停机（SIGINT / 程序内调用）：标记 → shutdown → drain PEL。
     */
    public static function beginShutdown(\Swoole\Server $server): void
    {
        if (!self::isEnabled() || self::isShuttingDown()) {
            return;
        }

        self::markShuttingDown();

        try {
            $server->shutdown();
        } catch (\Throwable $throwable) {
            // shutdown 可能重复调用，忽略
        }

        self::waitForStreamPelDrain();
    }

    public static function markShuttingDown(): void
    {
        if (self::$testShuttingDown !== null) {
            self::$testShuttingDown = true;

            return;
        }

        try {
            TableManager::getTable(self::TABLE)->set(self::ROW, [
                self::FIELD_SHUTTING_DOWN => 1,
                self::FIELD_STARTED_AT => time(),
            ]);
        } catch (\Throwable $throwable) {
            // Table 未创建时（单测）忽略
        }
    }

    public static function isShuttingDown(): bool
    {
        if (self::$testShuttingDown !== null) {
            return self::$testShuttingDown;
        }

        try {
            $row = TableManager::getTable(self::TABLE)->get(self::ROW);

            return is_array($row) && (int) ($row[self::FIELD_SHUTTING_DOWN] ?? 0) === 1;
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    public static function shouldRejectNewConnections(): bool
    {
        return self::isEnabled() && self::isShuttingDown();
    }

    /** Stream 消费循环是否应继续拉取新消息 */
    public static function shouldStopConsuming(): bool
    {
        return self::isEnabled() && self::isShuttingDown();
    }

    /**
     * Master 侧轮询 XPENDING，直至本节点消费组 PEL 清空或超时。
     */
    public static function waitForStreamPelDrain(): void
    {
        if (!ClusterConfig::isEnabled() || !ClusterConfig::usesPushStreams()) {
            return;
        }

        $deadline = time() + self::drainTimeout();
        while (time() < $deadline) {
            $pending = self::streamPendingCount();
            if ($pending === 0) {
                return;
            }
            usleep(200000);
        }
    }

    public static function streamPendingCount(): int
    {
        if (!ClusterConfig::isEnabled() || !ClusterConfig::usesPushStreams()) {
            return 0;
        }

        try {
            return (int) ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis): int {
                $streamKey = ClusterConfig::pushStreamKeyForServer(ClusterNodeIdentity::getServerId());
                $group = ClusterConfig::pushStreamGroup();

                return (int) $redis->xPendingCount($streamKey, $group);
            });
        } catch (\Throwable $throwable) {
            return -1;
        }
    }

    /** 单测：使用内存标志替代 Table */
    public static function useMemoryFlagForTest(): void
    {
        self::$testShuttingDown = false;
    }

    public static function setShuttingDownForTest(bool $value): void
    {
        self::$testShuttingDown = $value;
    }

    public static function resetForTest(): void
    {
        self::$testShuttingDown = null;
    }
}
