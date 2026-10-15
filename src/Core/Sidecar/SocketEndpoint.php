<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;

/**
 * 按实例隔离 UDS 路径，并只删除无人监听的 socket 残留。
 */
final class SocketEndpoint
{
    /**
     * 本次进程启动时刻，格式 Ymd-His。一次启动里只取一次，多个 sidecar 共用。
     */
    private static ?string $startedAt = null;

    /**
     * 本次启动的时间戳，写进 socket 文件名：{APP_NAME}-{Ymd}-{His}-{port}-{name}.sock。
     */
    public static function startedAt(): string
    {
        return self::$startedAt ??= date('Ymd-His');
    }

    /**
     * 当前进程的服务目录名。同一份代码同时跑多种服务时，各自的 sock 放在不同子目录，
     * 重启时只扫自己这一层里没人监听的 sock。
     * cron / script / daemon 按服务类型；都不是时用 cli（HTTP、WebSocket 等协议服务）。
     */
    public static function serviceName(): string
    {
        if (SystemEnv::isCronService()) {
            return 'cron';
        }
        if (SystemEnv::isScriptService()) {
            return 'script';
        }
        if (SystemEnv::isDaemonService()) {
            return 'daemon';
        }

        return 'cli';
    }

    /**
     * 默认 socket 父目录：{runtimeDir}/sidecars_sock。runtimeDir 为空时用 APP_PATH/runtime。
     * 真正放文件的是它下面的服务子目录，见 socketDirectory()。
     */
    public static function sockDirectory(string $runtimeDir): string
    {
        $dir = $runtimeDir;
        if ($dir === '') {
            $dir = defined('APP_PATH') ? APP_PATH . '/runtime' : '';
        }
        if ($dir === '') {
            return '';
        }

        return rtrim($dir, '/') . '/sidecars_sock';
    }

    /**
     * sock 文件所在目录。$configuredPath 是 sidecar_socket_path（目录），为空时用 sockDirectory()。
     * 两种情况都会再加一层服务目录：/data/socks 在 cli 下变成 /data/socks/cli。
     */
    public static function socketDirectory(string $configuredPath, string $runtimeDir): string
    {
        $dir = $configuredPath !== '' ? rtrim($configuredPath, '/') : self::sockDirectory($runtimeDir);
        if ($dir === '') {
            return '';
        }

        return $dir . '/' . self::serviceName();
    }

    /**
     * 文件名固定为 {APP_NAME}-{Ymd}-{His}-{port}-{name}.sock，不接受调用方指定文件名。
     */
    public static function fileName(string $app, int $port, string $name): string
    {
        return $app . '-' . self::startedAt() . '-' . $port . '-' . $name . '.sock';
    }

    /**
     * $configuredPath 是目录（sidecar_socket_path），为空时用 sockDirectory()。
     * 返回值才是 sock 文件的绝对路径。
     */
    public static function resolve(string $name, string $configuredPath, string $runtimeDir): string
    {
        $app = BaseServer::getAppName();
        $port = (int) (BaseServer::getConf()['port'] ?? 0);
        if ($app === '' || $port <= 0) {
            throw new SidecarException('cannot build sidecar socket path without app name and port');
        }
        $dir = self::socketDirectory($configuredPath, $runtimeDir);
        if ($dir === '') {
            throw new SidecarException('cannot build sidecar socket path without a runtime directory');
        }

        // 默认目录名 sidecars_sock 已写入 .gitignore，socket 文件不进入仓库。
        // 文件名带启动时刻。启动前只删本服务目录里没人监听的 .sock，目录和正在用的 sock 都留着。
        $path = $dir . '/' . self::fileName($app, $port, $name);
        // macOS sun_path 104 字节含结尾 NUL，Linux 108。超了 listen 会失败，这里提前报出来。
        $limit = PHP_OS_FAMILY === 'Darwin' ? 103 : 107;
        if (strlen($path) > $limit) {
            throw new SidecarException('sidecar socket path exceeds ' . $limit . ' bytes: ' . $path);
        }

        return $path;
    }

    /**
     * 启动 sidecar 之前，只删这个服务目录里没人监听的 .sock 文件。目录本身不删。
     * 连得上的 sock 说明还有进程在用（例如另一个 script 正在跑），留下。
     * 普通文件、子目录、符号链接都不动。目录名不是 cli、cron、script、daemon 就拒绝，避免扫到别的目录。
     */
    public static function clearUnusedSockets(string $dir): void
    {
        if ($dir === '' || !file_exists($dir)) {
            return;
        }
        if (is_link($dir)) {
            throw new SidecarException('sidecar socket directory must not be a symlink: ' . $dir);
        }
        $real = realpath($dir);
        $allowed = ['cli', 'cron', 'script', 'daemon'];
        if ($real === false || !is_dir($real) || !in_array(basename($real), $allowed, true)) {
            throw new SidecarException('refusing to clean sidecar socket directory: ' . $dir);
        }
        $items = scandir($real);
        if (!is_array($items)) {
            throw new SidecarException('cannot read sidecar socket directory: ' . $real);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || !str_ends_with(strtolower($item), '.sock')) {
                continue;
            }
            $path = $real . '/' . $item;
            if (is_link($path) || !self::isSocketFile($path) || self::isListening($path)) {
                continue;
            }
            if (self::withoutWarning(static fn () => unlink($path)) !== true) {
                throw new SidecarException('cannot unlink stale sidecar socket: ' . $path);
            }
        }
    }

    private static function isSocketFile(string $path): bool
    {
        $stat = self::withoutWarning(static fn () => lstat($path));
        if (!is_array($stat)) {
            return false;
        }

        return ($stat['mode'] & 0170000) === 0140000;
    }

    /**
     * 200 毫秒内能连上，说明有进程在 listen。
     */
    private static function isListening(string $path): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = self::withoutWarning(static function () use ($path, &$errno, &$errstr) {
            return stream_socket_client('unix://' . $path, $errno, $errstr, 0.2);
        });
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
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
        if ($type === 0120000 || !self::isSocketFile($path)) {
            throw new SidecarException('sidecar socket path exists and is not a stale socket: ' . $path);
        }
        if (self::isListening($path)) {
            throw new SidecarException('sidecar socket is already listening: ' . $path);
        }
        if (self::withoutWarning(static fn () => unlink($path)) !== true) {
            throw new SidecarException('cannot unlink stale sidecar socket: ' . $path);
        }
    }

    /**
     * shutdown 时删本进程登记的 sock。文件不存在或删不掉都不抛错，残留留着。
     */
    public static function unlinkQuiet(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            return;
        }
        self::withoutWarning(static fn () => unlink($path));
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
