<?php

declare(strict_types=1);

namespace Swoolefy\Mqtt;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;

/**
 * MQTT 优雅停机协调（Master + Worker 共享 Table 标志）。
 *
 * 流程：
 * 1. SIGTERM / cli stop → BeforeShutdown 置 shutting_down，Swoole 停 accept
 * 2. CONNECT 拒绝（CONNACK Server unavailable / shutting down）
 * 3. 已有连接：拒绝新 SUBSCRIBE / 新 PUBLISH；放行 PING 与 QoS 完成报文（PUBREL/PUBACK/PUBREC/PUBCOMP）
 * 4. WorkerStop：等待本 Worker 在途 QoS 清空或 drain_timeout → 再关 fd
 * 5. StopCmd 等待 ≥ drain_timeout + max_wait_time，避免提前 SIGKILL
 *
 * @see docs 与 README「优雅停机」
 */
final class MqttShutdownCoordinator
{
    public const TABLE = 'table_mqtt_shutdown';

    private const ROW = '_global';

    private const FIELD_SHUTTING_DOWN = 'shutting_down';

    private const FIELD_STARTED_AT = 'started_at';

    /** @var bool|null 单测内存标志 */
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

    /** @return array<string, mixed> */
    public static function settings(): array
    {
        try {
            $conf = Swfy::getConf();
        } catch (\Throwable) {
            $conf = [];
        }
        $settings = $conf['graceful_shutdown'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    public static function isEnabled(): bool
    {
        // 单测内存标志模式视为已启用，便于测 shouldReject*
        if (self::$testShuttingDown !== null) {
            return true;
        }

        return !empty(self::settings()['enable']);
    }

    public static function drainTimeout(): int
    {
        return max(1, (int) (self::settings()['drain_timeout'] ?? 30));
    }

    public static function recommendedStopTimeout(int $maxWaitTime = 10): int
    {
        return self::drainTimeout() + max(1, $maxWaitTime) + 5;
    }

    public static function recommendedForceKillTimeout(int $maxWaitTime = 10): int
    {
        return self::drainTimeout() + max(1, (int) ceil($maxWaitTime / 2));
    }

    public static function rejectReason(): string
    {
        $reason = (string) (self::settings()['reject_reason'] ?? 'server shutting down');

        return $reason !== '' ? $reason : 'server shutting down';
    }

    public static function registerServerShutdownHook(\Swoole\Server $server): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $server->on('BeforeShutdown', static function (\Swoole\Server $server): void {
            self::onBeforeShutdown($server);
        });
    }

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

    public static function onBeforeShutdown(\Swoole\Server $server): void
    {
        unset($server);
        if (!self::isEnabled() || self::isShuttingDown()) {
            return;
        }

        self::markShuttingDown();
    }

    public static function beginShutdown(\Swoole\Server $server): void
    {
        if (!self::isEnabled() || self::isShuttingDown()) {
            return;
        }

        self::markShuttingDown();

        try {
            $server->shutdown();
        } catch (\Throwable) {
            // ignore duplicate shutdown
        }
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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            return false;
        }
    }

    /** 是否拒绝新 TCP / 新 CONNECT */
    public static function shouldRejectNewSessions(): bool
    {
        return self::isEnabled() && self::isShuttingDown();
    }

    /**
     * 是否拒绝新业务（SUBSCRIBE / 新 PUBLISH）。
     * QoS 完成报文与 PING 仍应放行。
     */
    public static function shouldRejectNewWork(): bool
    {
        return self::isEnabled() && self::isShuttingDown();
    }

    /**
     * WorkerStop：等待本 Worker 在途 QoS 清空或超时。
     */
    public static function waitForLocalPendingDrain(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $deadline = time() + self::drainTimeout();
        while (time() < $deadline) {
            if (MqttSessionManager::getInstance()->pendingWorkCount() === 0) {
                return;
            }
            usleep(50000);
        }
    }

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
