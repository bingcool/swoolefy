<?php

declare(strict_types=1);

namespace Swoolefy\Support;

/**
 * Support 模块轻量日志 —— CLI / 单测 / 未 boot LogManager 时可用。
 */
final class SupportLog
{
    /** @var callable(string, string, array<string, mixed>): void|null */
    private static $testHandler = null;

    /** @param array<string, mixed> $context */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        if (self::$testHandler !== null) {
            (self::$testHandler)($channel, $message, $context);

            return;
        }

        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        error_log("[{$channel}] {$message}{$suffix}");
    }

    /** @param callable(string, string, array<string, mixed>): void $handler */
    public static function setTestHandler(callable $handler): void
    {
        self::$testHandler = $handler;
    }

    public static function resetTestHandler(): void
    {
        self::$testHandler = null;
    }
}
