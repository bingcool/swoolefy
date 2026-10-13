<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar\Plugin;

/**
 * 一个插件自己的配置键。进程公共字段仍由 SidecarSpec 校验。
 */
interface SidecarPluginSpec
{
    public static function name(): string;

    /**
     * @return list<string> 仅本插件的 sidecar_* 键
     */
    public static function keys(): array;

    /**
     * 校验并返回要写回配置项的字段。只含本插件的键。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fields(array $row): array;
}
