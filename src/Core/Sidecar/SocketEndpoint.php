<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoolefy\Core\BaseServer;

/**
 * 按实例隔离 UDS 路径，并只删除无人监听的 socket 残留。
 */
final class SocketEndpoint
{
    public static function resolve(string $name, string $configuredPath, string $runtimeDir): string
    {
        if ($configuredPath !== '') {
            return $configuredPath;
        }
        $app = BaseServer::getAppName();
        $port = (int) (BaseServer::getConf()['port'] ?? 0);
        if ($app === '' || $port <= 0) {
            throw new SidecarException('cannot build sidecar socket path without app name and port');
        }

        return rtrim($runtimeDir, '/') . '/sidecars/' . $app . '-' . $port . '-' . $name . '.sock';
    }

    public static function prepare(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new SidecarException('sidecar socket path must be an absolute path');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new SidecarException('cannot create sidecar socket directory');
        }
        chmod($dir, 0700);

        // 框架 error handler 把 warning 抛成异常，@ 压不住。socket 由 Go 在 listen 时创建，首次启动文件不存在。
        $stat = self::withoutWarning(static fn () => lstat($path));
        if (!is_array($stat)) {
            return;
        }
        $type = $stat['mode'] & 0170000;
        $isSocket = $type === 0140000;
        if ($type === 0120000 || !$isSocket) {
            throw new SidecarException('sidecar socket path exists and is not a stale socket: ' . $path);
        }
        $errno = 0;
        $errstr = '';
        $fp = self::withoutWarning(static function () use ($path, &$errno, &$errstr) {
            return stream_socket_client('unix://' . $path, $errno, $errstr, 0.2);
        });
        if (is_resource($fp)) {
            fclose($fp);
            throw new SidecarException('sidecar socket is already listening: ' . $path);
        }
        if (self::withoutWarning(static fn () => unlink($path)) !== true) {
            throw new SidecarException('cannot unlink stale sidecar socket: ' . $path);
        }
    }

    /**
     * 只吞掉这一次调用的 warning，避免 SwoolefyException::handleError 把它变成异常。
     */
    private static function withoutWarning(callable $call): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
