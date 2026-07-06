<?php

declare(strict_types=1);

namespace Swoolefy\Support;

use Swoolefy\Core\Log\LogManager;

/**
 * Support 模块轻量日志门面。
 *
 * 默认通过框架日志组件 support_log 输出；在无法获取 logger 时回退 error_log。
 *
 * 单测可通过 setTestHandler() 捕获日志断言。
 */
final class SupportLog
{
    public const CHANNEL = 'support_log';

    /** @var callable(string, string, array<string, mixed>): void|null 单测注入，非 null 时跳过 error_log */
    private static $testHandler = null;

    /**
     * 记录 info 级别日志。
     *
     * @param string               $channel 模块标识，如 mcp、workflow_audit
     * @param string               $message 人类可读描述
     * @param array<string, mixed> $context 结构化上下文
     */
    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('info', $channel, $message, $context);
    }

    /**
     * 记录 error 级别日志。
     *
     * @param string               $channel 模块标识，如 mcp、workflow_audit
     * @param string               $message 人类可读描述
     * @param array<string, mixed> $context 结构化上下文
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('error', $channel, $message, $context);
    }

    /**
     * 记录 warning 级别日志。
     *
     * @param string               $channel 模块标识，如 mcp、chat_history
     * @param string               $message 人类可读描述
     * @param array<string, mixed> $context 结构化上下文（JSON 附加到日志行）
     */
    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('warning', $channel, $message, $context);
    }

    /** @param 'info'|'warning' $level */
    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        if (self::$testHandler !== null) {
            (self::$testHandler)($channel, $message, $context);
            return;
        }

        $logger = LogManager::getInstance()->getLogger(self::CHANNEL);
        if (!empty($logger)) {
            $logger->{$level}("[{$channel}] {$message}", false, $context);
            return;
        }

        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        error_log("[" . strtoupper($level) . "][{$channel}] {$message}{$suffix}");
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
