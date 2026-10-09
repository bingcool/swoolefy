<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Sidecar\Dto\SidecarResponseDto;

/**
 * Sidecar 调用日志。
 *
 * 默认通过框架日志组件 sidecar_log 输出；拿不到 logger 时回退 error_log。
 * 只记 sidecar、method、requestId、code、durationMs，不记 payload 和密钥。
 * 单测可通过 setTestHandler() 捕获日志断言。
 */
final class SidecarLog
{
    public const CHANNEL = 'sidecar_log';

    /** @var callable(string, string, array<string, mixed>): void|null 单测注入，非 null 时跳过 error_log */
    private static $testHandler = null;

    public static function write(string $sidecar, string $method, string $requestId, string $code, int $durationMs): void
    {
        $context = [
            'sidecar' => $sidecar,
            'method' => $method,
            'requestId' => $requestId,
            'code' => $code,
            'durationMs' => $durationMs,
        ];
        $message = sprintf('method=%s requestId=%s code=%s durationMs=%d', $method, $requestId, $code, $durationMs);
        self::emit(self::levelFor($code), $sidecar, $message, $context);
    }

    /**
     * @param 'info'|'warning'|'error' $level
     * @param array<string, mixed> $context
     */
    private static function emit(string $level, string $channel, string $message, array $context): void
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

        $suffix = $context === [] ? '' : ' ' . (json_encode($context, JSON_UNESCAPED_UNICODE) ?: '{"error":"context_not_serializable"}');
        error_log('[' . strtoupper($level) . "][{$channel}] {$message}{$suffix}");
    }

    /** @return 'info'|'warning'|'error' */
    private static function levelFor(string $code): string
    {
        return match ($code) {
            SidecarResponseDto::CODE_OK => 'info',
            SidecarResponseDto::CODE_INVALID_ARGUMENT,
            SidecarResponseDto::CODE_METHOD_NOT_FOUND,
            SidecarResponseDto::CODE_OVERLOADED => 'warning',
            default => 'error',
        };
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
