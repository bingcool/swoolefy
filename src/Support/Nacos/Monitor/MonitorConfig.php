<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\ServiceConfig;

/**
 * Monitor 配置：Nacos 服务器 + application.yaml → nacos.monitor_config_change。
 */
final class MonitorConfig
{
    public function __construct(
        public readonly NacosConfig $nacosConfig,
        public readonly ServiceConfig $serviceConfig,
        public readonly string $envFile,
        public readonly string $lockFile,
        public readonly int $listenerTimeoutMs,
        public readonly int $failedWaitMs,
    ) {
    }

    public static function load(): self
    {
        $appPath = ApplicationConfig::resolveAppPath();
        $nacosConfig = NacosConfig::load();
        $monitor = ApplicationConfig::load()->nacosSection('monitor_config_change');
        $appName = defined('APP_NAME') ? (string) APP_NAME : basename($appPath);

        return new self(
            nacosConfig: $nacosConfig,
            serviceConfig: ServiceConfig::load(),
            envFile: ApplicationConfig::pickString($monitor, 'env_file', 'NACOS_ENV_FILE', $appPath . '/.env'),
            lockFile: ApplicationConfig::pickString($monitor, 'lock_file', 'NACOS_RELOAD_LOCK', '/tmp/swoolefy_' . strtolower($appName) . '_nacos_restart.lock'),
            listenerTimeoutMs: ApplicationConfig::pickInt($monitor, 'listener_timeout_ms', 'NACOS_LISTENER_TIMEOUT_MS', 30_000),
            failedWaitMs: ApplicationConfig::pickInt($monitor, 'failed_wait_ms', 'NACOS_LISTENER_FAILED_MS', 3_000),
        );
    }
}
