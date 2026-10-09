<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

/**
 * 读取 APP_PATH/Config/sidecar.php，供协议 conf.php 并入。
 * 文件不存在时返回空段，不拉起进程。
 */
final class SidecarConfig
{
    /**
     * @return array{sidecar_runtime_dir: string, sidecars: array<mixed>, rocketmq_consumers: array<mixed>}
     */
    public static function fromApp(): array
    {
        $empty = [
            'sidecar_runtime_dir' => '',
            'sidecars' => [],
            'rocketmq_consumers' => [],
        ];
        if (!defined('APP_PATH') || !is_string(APP_PATH) || APP_PATH === '') {
            return $empty;
        }
        $file = rtrim(APP_PATH, '/') . '/Config/sidecar.php';
        if (!is_file($file)) {
            return $empty;
        }
        $loaded = include $file;
        if (!is_array($loaded)) {
            throw new SidecarException('Config/sidecar.php must return an array');
        }
        foreach (array_keys($loaded) as $key) {
            if (!is_string($key) || !in_array($key, ['sidecar_runtime_dir', 'sidecars', 'rocketmq_consumers'], true)) {
                throw new SidecarException('unknown sidecar config key');
            }
        }

        return [
            'sidecar_runtime_dir' => $loaded['sidecar_runtime_dir'] ?? '',
            'sidecars' => $loaded['sidecars'] ?? [],
            'rocketmq_consumers' => $loaded['rocketmq_consumers'] ?? [],
        ];
    }
}
