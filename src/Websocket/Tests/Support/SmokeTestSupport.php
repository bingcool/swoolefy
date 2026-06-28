<?php

namespace Swoolefy\Websocket\Tests\Support;

use Swoole\Coroutine\Http\Client;

/**
 * Websocket 冒烟测试公共辅助（host/port 解析、服务预检、upgrade 诊断）。
 */
final class SmokeTestSupport
{
    /** @return array{0: string, 1: int} */
    public static function endpoint(): array
    {
        $host = trim((string) (getenv('WS_HOST') ?: '127.0.0.1'));
        $port = (int) (getenv('WS_PORT') ?: getenv('WORKER_PORT') ?: 9508);

        return [$host, max(1, $port)];
    }

    public static function shouldSkipIfServerDown(): bool
    {
        return in_array(strtolower((string) getenv('WS_SMOKE_SKIP_IF_DOWN')), ['1', 'true', 'yes'], true);
    }

    /**
     * TCP 预检：服务未启动时给出可操作的错误信息。
     */
    public static function ensureServerAvailable(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            2.0,
            STREAM_CLIENT_CONNECT
        );

        if ($socket !== false) {
            fclose($socket);

            return;
        }

        $hint = 'Start server first: SWOOLEFY_CLI_ENV=dev php cli.php start WebsocketService'
            . ' (override host/port via WS_HOST / WS_PORT / WORKER_PORT)';

        if (self::shouldSkipIfServerDown()) {
            echo "[SKIP] websocket smoke (server unavailable at {$host}:{$port}: {$errstr})\n";
            exit(0);
        }

        throw new \RuntimeException("WebsocketService not reachable at {$host}:{$port} ({$errstr}). {$hint}");
    }

    /**
     * WebSocket 升级，失败时附带 errCode/statusCode/body 便于排查。
     */
    public static function upgrade(Client $client, string $path, string $case): void
    {
        if ($client->upgrade($path)) {
            return;
        }

        $body = substr(trim((string) ($client->body ?? '')), 0, 240);
        $detail = sprintf(
            '%s: upgrade failed at %s:%s%s (errCode=%s errMsg=%s statusCode=%s%s)',
            $case,
            $client->host ?? '?',
            $client->port ?? '?',
            $path,
            (string) ($client->errCode ?? ''),
            (string) ($client->errMsg ?? ''),
            (string) ($client->statusCode ?? ''),
            $body !== '' ? " body={$body}" : ''
        );

        throw new \RuntimeException($detail);
    }
}
