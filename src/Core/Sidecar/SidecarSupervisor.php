<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar;

use Swoole\Process;
use Swoole\Server;
use Swoolefy\Core\BaseServer;

/**
 * 在 EventCtrl::init() 末尾把外部二进制注册到当前 Swoole Server。
 * HTTP、daemon、cron、script 走同一条路径。
 */
final class SidecarSupervisor
{
    public static function attach(): void
    {
        $conf = SidecarConfig::forServer(BaseServer::getConf());
        $runtimeDir = self::configuredRuntimeDir($conf);
        $rows = $conf['sidecars'] ?? [];
        if (!is_array($rows)) {
            throw new SidecarException('sidecars must be a list');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new SidecarException('sidecars item must be an array');
            }
            /** @var array<string, mixed> $row */
            $spec = SidecarSpec::fromRow($row, $runtimeDir);
            if ($spec === null) {
                continue;
            }
            if (SidecarRegistry::has($spec->name)) {
                throw new SidecarException('duplicate sidecar: ' . $spec->name);
            }
            SidecarRegistry::add($spec);
        }

        $specs = SidecarRegistry::all();
        if ($specs === []) {
            return;
        }

        $server = BaseServer::getServer();
        if (!$server instanceof Server) {
            throw new SidecarException('sidecar requires Swoole\\Server');
        }
        // 默认不扫目录。sidecar_clean_sockets_on_start 为 true 时，才删本服务目录里没人监听的 .sock。
        if (self::cleanSocketsOnStart($conf)) {
            $cleared = [];
            foreach ($specs as $spec) {
                $dir = dirname($spec->socketPath);
                if (!isset($cleared[$dir])) {
                    SocketEndpoint::clearUnusedSockets($dir);
                    $cleared[$dir] = true;
                }
            }
        }
        foreach ($specs as $spec) {
            SocketEndpoint::prepare($spec->socketPath);
            self::spawn($server, $spec);
        }
    }

    /**
     * 本进程退出时删除自己登记的 sock。删不掉就留着，不抛错。
     */
    public static function unlinkOwnSockets(): void
    {
        foreach (SidecarRegistry::all() as $spec) {
            $path = $spec->socketPath;
            if ($path === '' || str_contains($path, "\0")) {
                continue;
            }
            SocketEndpoint::unlinkQuiet($path);
        }
    }

    /**
     * addProcess() 返回 0 也是成功，只有 false 失败。
     */
    public static function spawn(object $server, SidecarSpec $spec): int
    {
        if (!method_exists($server, 'addProcess')) {
            throw new SidecarException('sidecar server cannot addProcess');
        }
        $process = new Process(static function (Process $worker) use ($spec): void {
            foreach ($spec->environment() as $envName => $value) {
                if ($value === '') {
                    continue;
                }
                putenv($envName . '=' . $value);
            }
            // SDK 在 init() 里读这个变量，必须在 exec 之前写好。默认 user.home 为空，会去建 /logs。
            if (in_array('rocketmq', explode(',', $spec->plugins), true) && getenv('rocketmq.client.logRoot') === false) {
                $logRoot = (defined('LOG_PATH') ? LOG_PATH : (defined('APP_PATH') ? APP_PATH . '/Storage/Logs' : '')) . '/rocketmq';
                if ($logRoot !== '/rocketmq') {
                    putenv('rocketmq.client.logRoot=' . $logRoot);
                }
            }
            if ($worker->exec($spec->binary, $spec->args) === false) {
                fwrite(STDERR, "sidecar exec failed: {$spec->name}\n");
                exit(127);
            }
        }, false, 0, false);

        $id = $server->addProcess($process);
        if ($id === false) {
            throw new SidecarException('sidecar addProcess failed: ' . $spec->name);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $conf
     */
    private static function configuredRuntimeDir(array $conf): string
    {
        if (!array_key_exists('sidecar_runtime_dir', $conf) || $conf['sidecar_runtime_dir'] === '' || $conf['sidecar_runtime_dir'] === null) {
            return '';
        }
        $dir = $conf['sidecar_runtime_dir'];
        if (!is_string($dir) || !str_starts_with($dir, '/') || str_contains($dir, "\0")) {
            throw new SidecarException('sidecar_runtime_dir must be an absolute path');
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $conf
     */
    private static function cleanSocketsOnStart(array $conf): bool
    {
        if (!array_key_exists('sidecar_clean_sockets_on_start', $conf)) {
            return false;
        }
        $parsed = filter_var($conf['sidecar_clean_sockets_on_start'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new SidecarException('sidecar_clean_sockets_on_start is invalid');
        }

        return $parsed;
    }

    /**
     * Master 的 BeforeShutdown 里删自己的 sock。须在 Server::start() 之前调用。
     * ManagerStop 再删一次。WebSocket、MQTT 若覆盖 BeforeShutdown，会先调用 unlinkOwnSockets()。
     */
    public static function bindShutdownHook(Server $server): void
    {
        $server->on('BeforeShutdown', static function (Server $server): void {
            unset($server);
            self::unlinkOwnSockets();
        });
    }
}
