<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

/**
 * 启动前收集 Sidecar 声明。Worker 通过 fork 继承同一份路径。
 */
final class SidecarRegistry
{
    /** @var array<string, SidecarSpec> */
    private static array $specs = [];

    public static function add(SidecarSpec $spec): void
    {
        if (isset(self::$specs[$spec->name])) {
            throw new SidecarException('duplicate sidecar: ' . $spec->name);
        }
        self::$specs[$spec->name] = $spec;
    }

    public static function has(string $name): bool
    {
        return isset(self::$specs[$name]);
    }

    public static function get(string $name): SidecarSpec
    {
        if (!isset(self::$specs[$name])) {
            throw new SidecarException('sidecar is not registered: ' . $name);
        }

        return self::$specs[$name];
    }

    /**
     * @return list<SidecarSpec>
     */
    public static function all(): array
    {
        return array_values(self::$specs);
    }

    public static function reset(): void
    {
        self::$specs = [];
    }
}
