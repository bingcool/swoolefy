<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

/**
 * 合并框架 SystemSidecar 与应用 Config/sidecar.php。
 * 应用文件不存在或返回 [] 时，只保留框架自带的 Sidecar。
 */
final class SidecarConfig
{
    /**
     * 每一项都适用的公共数值。单项没写时填入。值来自 .env，缺省为括号中的数字。
     *
     * @return array<string, int>
     */
    public static function publicSettings(): array
    {
        return [
            'sidecar_max_frame_bytes' => self::envInt('SIDECAR_MAX_FRAME_BYTES', 16777216),
            'sidecar_default_timeout_ms' => self::envInt('SIDECAR_DEFAULT_TIMEOUT_MS', 3000),
            'sidecar_max_timeout_ms' => self::envInt('SIDECAR_MAX_TIMEOUT_MS', 30000),
            'sidecar_max_inflight' => self::envInt('SIDECAR_MAX_INFLIGHT', 128),
            'sidecar_shutdown_grace_ms' => self::envInt('SIDECAR_SHUTDOWN_GRACE_MS', 5000),
        ];
    }

    /**
     * @return array{sidecar_runtime_dir: string, sidecars: array<mixed>, rocketmq_consumers: array<mixed>}
     */
    public static function fromApp(): array
    {
        return self::merge(self::loadPhp(self::systemFile()), self::loadApp());
    }

    /**
     * 协议 conf 已经展开 sidecar 字段时沿用那一份。没有这些键时再读 SystemSidecar 与 Config/sidecar.php。
     *
     * @param array<string, mixed> $conf
     * @return array<string, mixed>
     */
    public static function forServer(array $conf): array
    {
        if (
            array_key_exists('sidecars', $conf)
            || array_key_exists('rocketmq_consumers', $conf)
            || array_key_exists('sidecar_runtime_dir', $conf)
        ) {
            return $conf;
        }

        return self::fromApp();
    }

    /**
     * @param array<string, mixed> $system
     * @param array<string, mixed> $app
     * @return array{sidecar_runtime_dir: string, sidecars: list<array<string, mixed>>, rocketmq_consumers: list<mixed>}
     */
    public static function merge(array $system, array $app): array
    {
        $app = self::normalizeApp($app);
        $runtimeDir = $system['sidecar_runtime_dir'] ?? '';
        if (!is_string($runtimeDir)) {
            throw new SidecarException('sidecar_runtime_dir must be a string');
        }
        if (isset($app['sidecar_runtime_dir']) && is_string($app['sidecar_runtime_dir']) && $app['sidecar_runtime_dir'] !== '') {
            $runtimeDir = $app['sidecar_runtime_dir'];
        }

        $sidecars = array_merge(self::rows($system['sidecars'] ?? [], 'system'), self::rows($app['sidecars'] ?? [], 'app'));
        $public = self::publicSettings();
        foreach ($sidecars as &$row) {
            foreach ($public as $key => $value) {
                if (!array_key_exists($key, $row)) {
                    $row[$key] = $value;
                }
            }
        }
        unset($row);

        return [
            'sidecar_runtime_dir' => $runtimeDir,
            'sidecars' => $sidecars,
            'rocketmq_consumers' => array_merge(
                self::rows($system['rocketmq_consumers'] ?? [], 'system consumers'),
                self::rows($app['rocketmq_consumers'] ?? [], 'app consumers')
            ),
        ];
    }

    /**
     * @param array<mixed> $app
     * @return array<string, mixed>
     */
    private static function normalizeApp(array $app): array
    {
        if ($app === []) {
            return [];
        }
        if (array_is_list($app)) {
            return ['sidecars' => $app];
        }
        foreach (array_keys($app) as $key) {
            if (!is_string($key) || !in_array($key, ['sidecar_runtime_dir', 'sidecars', 'rocketmq_consumers'], true)) {
                throw new SidecarException('unknown sidecar config key');
            }
        }

        return $app;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows, string $label): array
    {
        if ($rows === null || $rows === []) {
            return [];
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new SidecarException($label . ' must be a list');
        }
        $list = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new SidecarException($label . ' sidecar item must be an array');
            }
            /** @var array<string, mixed> $row */
            $list[] = $row;
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadApp(): array
    {
        if (!defined('APP_PATH') || !is_string(APP_PATH) || APP_PATH === '') {
            return [];
        }
        $file = rtrim(APP_PATH, '/') . '/Config/sidecar.php';
        if (!is_file($file)) {
            return [];
        }

        return self::loadPhp($file);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadPhp(string $file): array
    {
        $loaded = include $file;
        if (!is_array($loaded)) {
            throw new SidecarException(basename($file) . ' must return an array');
        }

        return $loaded;
    }

    private static function systemFile(): string
    {
        return __DIR__ . '/Config/SystemSidecar.php';
    }

    private static function envInt(string $key, int $default): int
    {
        $value = env($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new SidecarException($key . ' is invalid');
    }
}
