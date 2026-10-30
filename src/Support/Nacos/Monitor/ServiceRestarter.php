<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * 调用 cli.php restart（等同 RestartCmd），参数从运行时常量读取。
 *
 * k8s 模式下不拉起 restart 进程：直接让服务退出，容器随之中止，
 * 由 kubelet 按 restartPolicy 重建，启动前置回调再重新拉取 Nacos 配置。
 */
final class ServiceRestarter
{
    public function __construct(
        private readonly Log $logger,
        private readonly bool $k8sMode = false,
    ) {
    }

    public function restart(): void
    {
        if ($this->k8sMode) {
            $this->terminateMasterForKubeletRestart();
            return;
        }

        if (!defined('APP_NAME')) {
            throw NacosMonitorException::throw('APP_NAME is not defined, cannot restart');
        }

        $appName = (string) APP_NAME;
        $cliScript = $this->resolveCliScript();
        $phpBinary = SystemEnv::PhpBinFile();

        if (!is_file($cliScript)) {
            throw NacosMonitorException::throw('cli script not found: ' . $cliScript);
        }

        $command = sprintf(
            '%s %s restart %s --force=1 > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($cliScript),
            escapeshellarg($appName),
        );

        $this->logger->info('exec restart: ' . $command);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->logger->error(sprintf('restart command=%s exit code=%d', $command, $exitCode));
        }
    }

    private function resolveCliScript(): string
    {
        if (defined('WORKER_START_SCRIPT_FILE')) {
            return (string) WORKER_START_SCRIPT_FILE;
        }

        if (!empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['PWD'])
            && str_contains((string) $_SERVER['SCRIPT_FILENAME'], (string) $_SERVER['PWD'])) {
            return (string) $_SERVER['SCRIPT_FILENAME'];
        }

        if (defined('START_DIR_ROOT')) {
            return START_DIR_ROOT . '/cli.php';
        }

        throw NacosMonitorException::throw('cannot resolve cli script from system');
    }

    /**
     * 向服务主进程发 SIGTERM：进程退出后容器终止，kubelet 重建容器时由入口前置回调重新拉取配置。
     */
    private function terminateMasterForKubeletRestart(): void
    {
        $pid = $this->resolveServicePid();
        if ($pid <= 0) {
            throw NacosMonitorException::throw('cannot resolve service pid in k8s restart mode');
        }

        $this->logger->info('k8s restart mode: send SIGTERM to service, pid=' . $pid);

        if (!\Swoole\Process::kill($pid, SIGTERM)) {
            throw NacosMonitorException::throw('send SIGTERM to service failed, pid=' . $pid);
        }
    }

    /**
     * 优先读 PID 文件，与 stop / restart 命令取同一个进程。自定义进程里 `$server->master_pid`
     * 可能是 0：初始用户进程由 Manager fork，此时 master_pid 还没写进共享内存。
     */
    private function resolveServicePid(): int
    {
        try {
            $pidFile = SystemEnv::getPidFile();
            if ('' !== $pidFile && is_file($pidFile)) {
                $pid = (int) trim((string) file_get_contents($pidFile));
                if ($pid > 0) {
                    return $pid;
                }
            }
        } catch (\Throwable) {
            // 未配置 pid_file 时继续回退
        }

        try {
            return (int) Swfy::getMasterPid();
        } catch (\Throwable) {
            return 0;
        }
    }
}
