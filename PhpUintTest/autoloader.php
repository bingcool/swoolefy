<?php

declare(strict_types=1);

namespace PhpUintTest;

/**
 * PhpUintTest\ PSR-4 加载（不进 composer.json，由 bootstrap.php require 本文件注册）。
 *
 * 路径约定：PhpUintTest\{Sub}\Foo → PhpUintTest/{Sub}/Foo.php
 */
final class Autoloader
{
    private static string $baseDirectory = __DIR__;

    /** @var array<string, true> */
    private static array $loaded = [];

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
        spl_autoload_register([self::class, 'autoload'], true, $prepend);
    }
}

Autoloader::register();
