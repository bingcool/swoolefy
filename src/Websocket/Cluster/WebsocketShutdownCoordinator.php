<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Table\TableManager;

/**
 * WebSocket 优雅停机协调（Master + Worker + 推送消费进程共享 Table 标志）。
 *
 * SIGTERM / cli stop 流程：
 * 1. Master 置 shutting_down + {@see \Swoole\Server::shutdown()} 停止 accept
 * 2. open / handshake 拒绝新连接（1008 / 503）
 * 3. Stream 消费进程 drain PEL（或超时）
 * 4. Master 轮询 XPENDING 直至清空或 drain_timeout
 * 5. Swoole 按 max_wait_time 等待 Worker 内正在处理的连接后退出
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

    public static function rejectReason(): string
    {
        $reason = (string) (self::settings()['reject_reason'] ?? 'server shutting down');

        return $reason !== '' ? $reason : 'server shutting down';
    }

    /**
     * Master 进程 Start 后注册 SIGTERM/SIGINT，触发停机序列。
     */
    public static function installMasterSignalHandler(\Swoole\Server $server): void
    {
        if (!self::isEnabled() || !extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = static function () use ($server): void {
            self::beginShutdown($server);
        };
        \Swoole\Process::signal(SIGTERM, $handler);
        \Swoole\Process::signal(SIGINT, $handler);
    }

    /**
     * 标记停机、停止 accept，并等待本节点 Stream PEL 排空（或超时）。
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
