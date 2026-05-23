<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\NacosConfig;

/**
 * Monitor 配置：Nacos 服务器 + application.yaml → nacos.monitor_config_change。
 */
final class MonitorConfig
{
    public function __construct(
        public readonly NacosConfig $nacos,
        public readonly string $envFile,
        public readonly string $lockFile,
        public readonly int $listenerTimeoutMs,
        public readonly int $failedWaitMs,
    ) {
    }

    public static function load(?string $appPath = null): self
    {
        $nacos = NacosConfig::load($appPath);
        $monitor = ApplicationConfig::load($nacos->appPath)->nacosSection('monitor_config_change');
        $appName = defined('APP_NAME') ? (string) APP_NAME : basename(rtrim($nacos->appPath, '/'));

        return new self(
            nacos: $nacos,
            envFile: ApplicationConfig::pickString($monitor, 'env_file', 'NACOS_ENV_FILE', rtrim($nacos->appPath, '/') . '/.env'),
            lockFile: ApplicationConfig::pickString($monitor, 'lock_file', 'NACOS_RELOAD_LOCK', '/tmp/swoolefy_' . strtolower($appName) . '_nacos_restart.lock'),
            listenerTimeoutMs: ApplicationConfig::pickInt($monitor, 'listener_timeout_ms', 'NACOS_LISTENER_TIMEOUT_MS', 30_000),
            failedWaitMs: ApplicationConfig::pickInt($monitor, 'failed_wait_ms', 'NACOS_LISTENER_FAILED_MS', 3_000),
        );
    }
}
