<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Support\Nacos\NacosConfig;

/**
 * Monitor 专用配置：公共 Nacos 配置 + 监听 / 重启相关项。
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
        $monitor = $nacos->section('monitor');
        $appName = defined('APP_NAME') ? (string) APP_NAME : basename(rtrim($nacos->appPath, '/'));

        return new self(
            nacos: $nacos,
            envFile: self::pickString($monitor, 'env_file', 'NACOS_ENV_FILE', rtrim($nacos->appPath, '/') . '/.env'),
            lockFile: self::pickString($monitor, 'lock_file', 'NACOS_RELOAD_LOCK', '/tmp/swoolefy_' . strtolower($appName) . '_nacos_restart.lock'),
            listenerTimeoutMs: self::pickInt($monitor, 'listener_timeout_ms', 'NACOS_LISTENER_TIMEOUT_MS', 30_000),
            failedWaitMs: self::pickInt($monitor, 'failed_wait_ms', 'NACOS_LISTENER_FAILED_MS', 3_000),
        );
    }

    private static function pickString(array $yaml, string $yamlKey, string $envKey, string $default): string
    {
        if (array_key_exists($yamlKey, $yaml) && '' !== (string) $yaml[$yamlKey]) {
            return (string) $yaml[$yamlKey];
        }

        $env = getenv($envKey);
        if (false !== $env && '' !== $env) {
            return (string) $env;
        }

        return $default;
    }

    private static function pickInt(array $yaml, string $yamlKey, string $envKey, int $default): int
    {
        if (array_key_exists($yamlKey, $yaml) && is_numeric($yaml[$yamlKey])) {
            return (int) $yaml[$yamlKey];
        }

        $env = getenv($envKey);
        if (false !== $env && is_numeric($env)) {
            return (int) $env;
        }

        return $default;
    }
}
