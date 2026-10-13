<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar\Plugin;

use Swoolefy\Core\Sidecar\SidecarException;

/**
 * 插件名到字段类。未登记的名字在 PHP 启动时失败，不会 exec。
 */
final class SidecarPluginRegistry
{
    /**
     * @return class-string<SidecarPluginSpec>
     */
    public static function get(string $name): string
    {
        return match ($name) {
            'rocketmq' => SidecarRocketMqSpec::class,
            default => throw new SidecarException('unknown sidecar plugin: ' . $name),
        };
    }
}
