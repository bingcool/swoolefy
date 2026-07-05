<?php

declare(strict_types=1);

namespace Swoolefy\Support;

/**
 * Support 模块轻量日志门面。
 *
 * Phase A 引入：MCP tools 加载失败、Redis ChatHistory 读写失败等场景
 * 在无法使用框架 LogManager 时（CLI / 单测）仍可通过 error_log 输出。
 *
 * 单测可通过 setTestHandler() 捕获日志断言。
 */
final class SupportLog
{
    /** @var callable(string, string, array<string, mixed>): void|null 单测注入，非 null 时跳过 error_log */
    private static $testHandler = null;

    /**
     * 记录 warning 级别日志。
     *
     * @param string               $channel 模块标识，如 mcp、chat_history
     * @param string               $message 人类可读描述
     * @param array<string, mixed> $context 结构化上下文（JSON 附加到日志行）
     */
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
