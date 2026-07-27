<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\Application\ServerLifecycleManager;
use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Infrastructure\ServerStatusRenderer;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 重启服务命令。
 *
 * 流程：停止 → 等待原进程退出 → 等待端口释放 → 启动新服务 → 验证启动成功
 *
 * 使用方式：
 *   php cli.php restart App
 *   php cli.php restart App --force=1
 *
 * 改造说明：
 * - 将原先 160+ 行的巨型 execute() 拆分为 6 个子方法
 * - 修复原 RestartCmd::serverStop() 第258行重复 kill managerName 的 Bug
 * - stop 逻辑委托给 ServerLifecycleManager
 * - exit(0) 改为 return Command::SUCCESS/FAILURE
 * - 忽略父进程的 SIGTERM（Nacos 等场景），避免被一起杀死
 */
#[AsCommand(name: 'restart')]
class RestartCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('restart the application')
             ->setHelp('<info>use php cli.php restart XXXXX or php cron.php|daemon.php restart XXXXX</info>');
    }

    /**
     * 执行重启命令。
     *
     * 拆分为 6 个步骤：
     * 1. 确认重启
     * 2. 停止服务（委托 ServerLifecycleManager）
     * 3. 等待原进程退出
     * 4. 等待端口释放
     * 5. 启动新服务（内部调用 start 命令）
     * 6. 验证启动成功
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        // 忽略父进程的终止信号（Nacos 等场景下防止被一起杀死）
        $this->ignoreTerminationSignalsFromParentServer();

        $ctx = $this->context();
        $appName = $ctx->appName;
        $force = $input->getOption(self::FORCE);

        // Step 1: 确认重启
        if (!$this->confirmRestart($appName, $force)) {
            $this->printCancelMessage($appName);
            return Command::SUCCESS;
        }

        $masterPid = $ctx->pid;

        // Step 2: 停止服务
        $manager = new ServerLifecycleManager();
        $result = $ctx->isWorkerService
            ? $manager->stopWorkerService($ctx)
            : $manager->stopServer($ctx);

        CmdLogWriter::write("重启服务：" . ($ctx->isWorkerService
            ? (defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : $appName)
            : $appName));

        // Step 3: 等待原进程退出
        $this->waitForShutdown($masterPid);

        // Step 4: 等待端口释放
        $this->waitPortReleasedForRestart('127.0.0.1', $ctx->port, $appName);

        // Step 5: 清理 restart PID 文件并启动新服务
        $this->cleanRestartPidFile();
        $this->invokeStartCommand($appName);

        // Step 6: 验证启动成功
        return $this->verifyStartup($masterPid, $ctx->pidFile, $appName);
    }

    /**
     * 确认重启操作。
     *
     * @param string $appName 应用名称
     * @param mixed  $force   --force 选项值
     * @return bool 是否确认重启
     */
    private function confirmRestart(string $appName, mixed $force): bool
    {
        if (!empty($force)) {
            return true;
        }

        $prompt = SystemEnv::isWorkerService()
            ? "1、你确定 【重启】 workerService【" . WORKER_SERVICE_NAME . "】? (yes or no)"
            : "1、你确定 【重启】 应用【{$appName}】? (yes or no)";

        $lineValue = initConsoleStyleIo()->ask($prompt);
        return strtolower($lineValue) === 'yes';
    }

    /**
     * 打印取消重启的消息。
     *
     * @param string $appName 应用名称
     */
    private function printCancelMessage(string $appName): void
    {
        $message = SystemEnv::isWorkerService()
            ? "你已放弃【重启】workerService【" . WORKER_SERVICE_NAME . "】,应用继续running中"
            : "你已放弃【重启】应用【{$appName}】,应用继续running中";

        fmtPrintInfo(PHP_EOL . $message);
    }

    /**
     * 等待原服务进程完全退出。
     *
     * 轮询检查 master PID 是否存活，每秒检查一次，最多等待 60 秒。
     *
     * @param int $masterPid 原 master 进程 PID
     */
    private function waitForShutdown(int $masterPid): void
    {
        if ($masterPid <= 0) {
            return;
        }

        $deadline = time() + 60;
        while (time() < $deadline) {
            if (!ProcessTreeTerminator::isAlive($masterPid)) {
                fmtPrintNote("-----------原服务进程已退出-----------");
                return;
            }
            fmtPrintNote("-----------原服务进程正在退出中...-----------");
            sleep(1);
        }

        fmtPrintWarning("-----------原服务进程退出超时，将继续尝试重启-----------");
    }

    /**
     * 等待端口释放，确保新服务可以绑定到同一端口。
     *
     * 端口释放策略：
     * 1. 尝试 fsockopen 连接端口，连接失败说明端口已释放
     * 2. 若启用 SO_REUSEPORT，按进程名杀死残留进程
     * 3. 超时（20秒）后强制清理端口占用
     *
     * @param string $host    监听地址
     * @param int    $port    监听端口
     * @param string $appName 应用名称
     */
    protected function waitPortReleasedForRestart(string $host, int $port, string $appName): void
    {
        $config = $this->loadAppProtocolConfig($appName);
        $reusePort = (bool) ($config['setting']['enable_reuse_port'] ?? false);
        $masterName = (string) ($config['master_process_name'] ?? 'php-swoolefy-http-master');
        $managerName = (string) ($config['manager_process_name'] ?? 'php-swoolefy-http-manager');
        $deadline = time() + 20;

        while (time() < $deadline) {
            if ($this->isPortReleased($host, $port)) {
                return;
            }

            // SO_REUSEPORT 模式：按进程名清理残留
            if ($reusePort) {
                ProcessTreeTerminator::killByProcessTitle($masterName);
                ProcessTreeTerminator::killByProcessTitle($managerName);
                ProcessTreeTerminator::killListenersOnPort($port);
            }

            fmtPrintInfo("-----------正在重启服务进程中，请等待-----------");
            sleep(3);
        }

        // 超时后强制清理
        fmtPrintWarning("-----------端口 {$port} 仍被占用，将尝试强制清理后继续启动-----------");
        ProcessTreeTerminator::killByProcessTitle($masterName);
        ProcessTreeTerminator::killByProcessTitle($managerName);
        ProcessTreeTerminator::killListenersOnPort($port);
    }

    /**
     * 检测端口是否已释放。
     *
     * 通过 fsockopen 尝试连接，连接失败说明端口未被占用（已释放）。
     *
     * @param string $host 地址
     * @param int    $port 端口
     * @return bool 端口是否已释放
     */
    protected function isPortReleased(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $err, 1);
        if ($connection) {
            fclose($connection);
            return false;
        }
        return true;
    }

    /**
     * 清理 restart 模式的 PID 文件。
     */
    private function cleanRestartPidFile(): void
    {
        $restartPidFile = SystemEnv::getRestartModelPidFile();
        if (file_exists($restartPidFile)) {
            unlink($restartPidFile);
        }
    }

    /**
     * 内部调用 start 命令启动新服务。
     *
     * 通过 Symfony Console 的 ArrayInput 机制内部调用 StartCmd，
     * 以 daemon 模式启动，并标记 start_model=restart。
     *
     * @param string $appName 应用名称
     */
    private function invokeStartCommand(string $appName): void
    {
        fmtPrintInfo("-----------正在重启服务进程中，请等待-----------");

        $input = new ArrayInput([
            'command' => 'start',
            self::APP_NAME => $appName,
            '--' . self::DAEMON => 1,
            '--' . self::START_MODEL => 'restart',
        ]);
        $output = new ConsoleOutput();
        $this->getApplication()->run($input, $output);
    }

    /**
     * 验证新服务是否启动成功。
     *
     * 验证策略（轮询，每秒检查一次，最多 30 秒）：
     * 1. PID 文件存在且 PID 与原 master 不同 → 新进程已启动
     * 2. restartPidFile 存在且进程存活 → restart 模式成功
     * 3. 超时后输出不确定提示
     *
     * @param int    $oldMasterPid 原 master 进程 PID
     * @param string $pidFile      PID 文件路径
     * @param string $appName      应用名称
     * @return int 退出码
     */
    private function verifyStartup(int $oldMasterPid, string $pidFile, string $appName): int
    {
        $waitTime = 30;
        $time = time();
        $restartPidFile = SystemEnv::getRestartModelPidFile();
        $phpBinFile = SystemEnv::PhpBinFile();

        // 确定 CLI 脚本文件路径
        $selfFile = (str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD']))
            ? $_SERVER['SCRIPT_FILENAME']
            : (defined('WORKER_START_SCRIPT_FILE') ? WORKER_START_SCRIPT_FILE : $_SERVER['SCRIPT_FILENAME']);

        while (true) {
            sleep(1);

            // PID 文件还未创建
            if (!file_exists($pidFile)) {
                if (time() - $time < $waitTime) {
                    continue;
                }
            }

            // 新 master PID 已写入且不同于旧 PID → 启动成功
            if (file_exists($pidFile)) {
                $newMasterPid = PidFileManager::read($pidFile);
                if ($newMasterPid > 0 && $newMasterPid !== $oldMasterPid && ProcessTreeTerminator::isAlive($newMasterPid)) {
                    return $this->printRestartSuccess($appName, $pidFile, $restartPidFile);
                }
            }

            // 超时处理
            if (time() - $time > $waitTime) {
                // restart 模式下检查 restartPidFile
                if (file_exists($restartPidFile)) {
                    $restartPid = PidFileManager::read($restartPidFile);
                    if ($restartPid > 0 && ProcessTreeTerminator::isAlive($restartPid)) {
                        return $this->printRestartSuccess($appName, $pidFile, $restartPidFile);
                    }
                }

                fmtPrintError("-----------无法确定是否重起成功，请使用 {$phpBinFile} {$selfFile} status {$appName} 查看进程是否启动成功!------------");
                return Command::FAILURE;
            }
        }
    }

    /**
     * 打印重启成功信息。
     *
     * WorkerService 和普通 Server 分别输出不同的成功提示。
     * 普通 Server 额外展示进程状态表格。
     *
     * @param string $appName        应用名称
     * @param string $pidFile        PID 文件路径
     * @param string $restartPidFile restart PID 文件路径
     * @return int 退出码
     */
    private function printRestartSuccess(string $appName, string $pidFile, string $restartPidFile): int
    {
        if (SystemEnv::isWorkerService()) {
            $serverName = defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : $appName;
            fmtPrintInfo("-----------【{$serverName}】服务重启成功！重启成功啦！重启成功啦！------------");
        } else {
            ServerStatusRenderer::render($appName, $pidFile);
            fmtPrintInfo("-----------看到进程表格，进程重启成功啦！重启成功啦！重启成功啦！------------");
        }

        // 清理 restartPidFile
        if (file_exists($restartPidFile)) {
            unlink($restartPidFile);
        }

        return Command::SUCCESS;
    }

    /**
     * 从 Nacos 等自定义进程内同步调用 restart 时，避免随 Master SIGTERM 一起退出。
     *
     * 使用 pcntl_signal 忽略 SIGTERM/SIGINT/SIGHUP 信号。
     */
    protected function ignoreTerminationSignalsFromParentServer(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        $ignore = static function (): void {};
        pcntl_signal(SIGTERM, $ignore);
        pcntl_signal(SIGINT, $ignore);
        pcntl_signal(SIGHUP, $ignore);
    }

    /**
     * 加载应用的 Protocol/conf.php 配置。
     *
     * @param string $appName 应用名称
     * @return array 配置数组
     */
    protected function loadAppProtocolConfig(string $appName): array
    {
        $path = APP_PATH . '/Protocol/conf.php';
        if (!is_file($path)) {
            $path = ROOT_PATH . '/' . $appName . '/Protocol/conf.php';
        }
        return is_file($path) ? (array) include $path : [];
    }
}
