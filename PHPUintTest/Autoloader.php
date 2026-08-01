<?php

declare(strict_types=1);

namespace PHPUintTest;

/**
 * PHPUintTest\ PSR-4 加载（不进 composer psr-4；由 register_dev_autoload / bootstrap 注册）。
 *
 * 路径约定：PHPUintTest\{Sub}\Foo → PHPUintTest/{Sub}/Foo.php
 */
final class Autoloader
{
    private static string $baseDirectory = __DIR__;

    /** @var array<string, true> */
    private static array $loaded = [];

    private static bool $registered = false;

    public static function autoload(string $className): void
    {
        if (isset(self::$loaded[$className])) {
            return;
        }

        $prefix = __NAMESPACE__ . '\\';
        if (!str_starts_with($className, $prefix)) {
            return;
        }

        $relative = substr($className, strlen($prefix));
        if ($relative === false || $relative === '') {
            return;
        }

        $filepath = self::$baseDirectory
            . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
            . '.php';

        if (!is_file($filepath)) {
            return;
        }

        require_once $filepath;
        self::$loaded[$className] = true;
    }

    public static function register(bool $prepend = false): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        spl_autoload_register([self::class, 'autoload'], true, $prepend);
    }
}

Autoloader::register();
