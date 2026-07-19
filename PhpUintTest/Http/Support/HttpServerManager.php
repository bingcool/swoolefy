<?php

declare(strict_types=1);

namespace PhpUintTest\Http\Support;

/**
 * HTTP 探活 / 可选自动启停（模式 A 默认；模式 B 见 SWOOLEFY_HTTP_AUTO_START）。
 *
 * 目标环境：macOS / Linux。不适配 Windows。
 */
final class HttpServerManager
{
    private static bool $startedByUs = false;

    public static function ensureAvailable(string $baseUrl): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (self::isReady($baseUrl)) {
            return;
        }

        $autoStart = filter_var(getenv('SWOOLEFY_HTTP_AUTO_START') ?: '0', FILTER_VALIDATE_BOOLEAN);
        if ($autoStart) {
            self::startDaemon();
            $timeout = max(1, (int) (getenv('SWOOLEFY_HTTP_READY_TIMEOUT') ?: 30));
            $deadline = time() + $timeout;
            while (time() < $deadline) {
                if (self::isReady($baseUrl)) {
                    self::$startedByUs = true;
                    register_shutdown_function(static fn () => self::stopIfStartedByUs());

                    return;
                }
                usleep(200_000);
            }
            self::stopIfStartedByUs();
            throw new HttpServerUnavailableException(
                "HTTP server not ready at {$baseUrl} after AUTO_START ({$timeout}s). See Test/Storage/Logs/phpunit-http.log",
            );
        }

        $skip = filter_var(getenv('SWOOLEFY_HTTP_SKIP_IF_DOWN') ?: '1', FILTER_VALIDATE_BOOLEAN);
        $msg = "HTTP server unavailable at {$baseUrl}. Start with: php cli.php start Test"
            . ($skip ? ' (skipping)' : '');
        throw new HttpServerUnavailableException($msg);
    }

    public static function isReady(string $baseUrl): bool
    {
        // 优先 K8s liveness 路径；失败再回退根路径（兼容旧应用未挂探针）
        foreach (['/health', '/healthz', '/'] as $path) {
            if (self::probeUrl(rtrim($baseUrl, '/') . $path)) {
                return true;
            }
        }

        return false;
    }

    private static function probeUrl(string $url): bool
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);

        return $body !== false;
    }

    private static function startDaemon(): void
    {
        $root = dirname(__DIR__, 3);
        $logDir = $root . '/Test/Storage/Logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $log = $logDir . '/phpunit-http.log';
        // CI 模式 B：restart --force 跳过交互确认，脏进程可复用同一端口
        $cmd = sprintf(
            'cd %s && php cli.php restart Test --force=1 --daemon=1 >> %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($log),
        );
        exec($cmd);
    }

    private static function stopIfStartedByUs(): void
    {
        if (!self::$startedByUs) {
            return;
        }
        self::$startedByUs = false;
        $root = dirname(__DIR__, 3);
        exec(sprintf('cd %s && php cli.php stop Test --force=1 >/dev/null 2>&1', escapeshellarg($root)));
    }
}
