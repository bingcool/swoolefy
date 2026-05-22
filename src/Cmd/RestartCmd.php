<?php
namespace Swoolefy\Cmd;

use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'restart',
)]
class RestartCmd extends BaseCmd
{
    protected static $defaultName = 'restart';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('stop the application')->setHelp('<info>use php cli.php restart XXXXX or php cron.php|daemon.php restart XXXXX</info>');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ignoreTerminationSignalsFromParentServer();

        $appName = $input->getArgument(self::APP_NAME);
        $force   = $input->getOption(self::FORCE);
        $lineValue = "";
        if (empty($force)) {
            if (SystemEnv::isWorkerService()) {
                $lineValue = initConsoleStyleIo()->ask( "1、你确定 【重启】 workerService【" . WORKER_SERVICE_NAME . "】? (yes or no)");
            } else {
                $lineValue = initConsoleStyleIo()->ask( "1、你确定 【重启】 应用【{$appName}】? (yes or no)");
            }
        }

        $pidFile = $this->getPidFile($appName);
        $masterPid = 0;
        if (file_exists($pidFile)) {
            $masterPid = intval(file_get_contents($pidFile));
        }


        if (strtolower($lineValue) == 'yes' || $force) {
            if (SystemEnv::isWorkerService()) {
                $this->workerStop($appName);
            } else {
                $this->serverStop($appName);
            }
        } else {
            if (SystemEnv::isWorkerService()) {
                fmtPrintInfo(PHP_EOL."你已放弃【重启】workerService【" . WORKER_SERVICE_NAME . "】,应用继续running中");
                exit(0);
            } else {
                fmtPrintInfo(PHP_EOL."你已放弃【重启】应用【{$appName}】,应用继续running中");
                exit(0);
            }
        }

        $this->writeLog("重启服务：".WORKER_SERVICE_NAME);

        while (true) {
            if ($masterPid > 0 && \Swoole\Process::kill($masterPid, 0)) {
                fmtPrintNote("-----------原服务进程正在退出中...-----------");
                sleep(1);
            }else {
                fmtPrintNote("-----------原服务进程已退出-----------");
                break;
            }
        }

        fmtPrintInfo("-----------正在重启服务进程中，请等待-----------");
        $this->waitPortReleasedForRestart('127.0.0.1', (int) WORKER_PORT, $appName);

        // delete restart pid file
        $restartPidFile = SystemEnv::getRestartModelPidFile();
        if (file_exists($restartPidFile)) {
            unlink($restartPidFile);
        }

        // send restart command to main worker process
        $phpBinFile = SystemEnv::PhpBinFile();
        $waitTime   = 30;
        if (SystemEnv::isWorkerService()) {
            // sleep max 30s
            $waitTime = 30;
        }

        if (str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD'])) {
            $selfFile = $_SERVER['SCRIPT_FILENAME'];
        }else {
            $selfFile = WORKER_START_SCRIPT_FILE;
        }

        $appNameOption = self::APP_NAME;
        $daemonOption = join('', ["--", self::DAEMON]);
        $startModelOption = join('', ["--", self::START_MODEL]);
        $input = new ArrayInput([
            'command' => "start",
            $appNameOption => $appName,
            $daemonOption => 1,
            $startModelOption => 'restart',
        ]);
        $output = new ConsoleOutput();
        $this->getApplication()->run($input, $output);

        $time = time();
        while (true) {
            sleep(1);
            if (!file_exists($pidFile)) {
                if (time() - $time < $waitTime) {
                    continue;
                }
            }

            $successFn = function ($appName, $pidFile) use($restartPidFile) {
                $serverName = WORKER_SERVICE_NAME;
                if (SystemEnv::isWorkerService()) {
                    fmtPrintInfo("-----------【{$serverName}】服务重启完成!------------");
                }
                if (SystemEnv::isWorkerService()) {
                    fmtPrintInfo("-----------看到此处，【{$serverName}】服务重启成功啦！重启成功啦！重启成功啦！------------");
                }else {
                    $this->serverStatus($appName, $pidFile);
                    fmtPrintInfo("-----------看到进程表格，进程重启成功啦！重启成功啦！重启成功啦！------------");
                }
                if (file_exists($restartPidFile)) {
                    unlink($restartPidFile);
                }
                exit(0);
            };

            // 新拉起的主进程id已经存在，说明新拉起的主进程已经启动成功
            if (file_exists($pidFile)) {
                $newMasterPid = intval(file_get_contents($pidFile));
                if ($newMasterPid > 0 && $newMasterPid != $masterPid && \Swoole\Process::kill($newMasterPid, 0)) {
                    $successFn($appName, $pidFile);
                }
            }

            // wait time out
            if (time() - $time > $waitTime) {
                // restart model 重启命令会记录restartPidFile, 所以仍需要判断一下
                if (file_exists($restartPidFile)) {
                    $restartPid = file_get_contents($restartPidFile);
                    // 存在的restartPid就是新拉起的主进程id
                    if ($restartPid > 0 && \Swoole\Process::kill($restartPid, 0)) {
                        $successFn($appName, $restartPidFile);
                    }
                }
                fmtPrintError("-----------无法确定是否重起成功，请使用 {$phpBinFile} {$selfFile} status {$appName} 查看进程是否启动成功!------------");
                exit(0);
            }
        }
    }

    /**
     * 判断端口是否已经释放
     *
     * @param $host
     * @param $port
     * @return bool
     */
    protected function isPortReleased($host, $port) {
        $timeout = 1;
        $connection = @fsockopen($host, $port, $errno, $err, $timeout);
        if ($connection) {
            fclose($connection);
            return false;
        } else {
            return true;
        }
    }

    /**
     * 从 Nacos 等自定义进程内同步调用 restart 时，避免随 Master SIGTERM 一起退出。
     */
    protected function ignoreTerminationSignalsFromParentServer(): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        $ignore = static function (): void {
        };
        pcntl_signal(SIGTERM, $ignore);
        pcntl_signal(SIGINT, $ignore);
        pcntl_signal(SIGHUP, $ignore);
    }

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

            if ($reusePort) {
                ProcessTreeTerminator::killByProcessTitle($masterName);
                ProcessTreeTerminator::killByProcessTitle($managerName);
                ProcessTreeTerminator::killListenersOnPort($port);
            }

            fmtPrintInfo("-----------正在重启服务进程中，请等待-----------");
            sleep(3);
        }

        fmtPrintWarning("-----------端口 {$port} 仍被占用，将尝试强制清理后继续启动-----------");
        ProcessTreeTerminator::killByProcessTitle($masterName);
        ProcessTreeTerminator::killByProcessTitle($managerName);
        ProcessTreeTerminator::killListenersOnPort($port);
    }

    protected function loadAppProtocolConfig(string $appName): array
    {
        $path = APP_PATH . '/Protocol/conf.php';
        if (!is_file($path)) {
            $path = ROOT_PATH . '/' . $appName . '/Protocol/conf.php';
        }

        return is_file($path) ? (array) include $path : [];
    }
    /**
     * @param string $appName
     * @return void
     */
    protected function serverStop(string $appName)
    {
        $config = $this->loadAppProtocolConfig($appName);
        $masterName = (string) ($config['master_process_name'] ?? 'php-swoolefy-http-master');
        $managerName = (string) ($config['manager_process_name'] ?? 'php-swoolefy-http-manager');

        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            ProcessTreeTerminator::killByProcessTitle($masterName);
            ProcessTreeTerminator::killByProcessTitle($managerName);
            return;
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid <= 0 || !ProcessTreeTerminator::isAlive($pid)) {
            fmtPrintInfo("Server has been stopped");
            @unlink($pidFile);
            ProcessTreeTerminator::killByProcessTitle($managerName);
            ProcessTreeTerminator::killByProcessTitle($managerName);
            return;
        }

        fmtPrintInfo("Server begin to stopping at " . date("Y-m-d H:i:s") . ", pid={$pid}. please wait a moment...");
        ProcessTreeTerminator::terminateHttpServerTree($pid, $masterName, $managerName);

        fmtPrintInfo("---------------------stop info-------------------");
        fmtPrintInfo("Server Stop OK. server stop at " . date("Y-m-d H:i:s"));
        @unlink($pidFile);
    }

    protected function workerStop($appName)
    {
        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            return;
        }

        $masterPid = file_get_contents($pidFile);
        if (is_numeric($masterPid) && $masterPid > 0) {
            $masterPid = (int)$masterPid;
        } else {
            return;
        }

        if (!\Swoole\Process::kill($masterPid, 0)) {
            fmtPrintInfo("Server has been stopped");
            return;
        }

        if (\Swoole\Process::kill($masterPid, 0)) {
            $pipeMsgDto = new \Swoolefy\Worker\Dto\PipeMsgDtoWorker();
            $pipeMsgDto->action = WORKER_CLI_STOP;
            $pipeMsg = serialize($pipeMsgDto);

            // mainWorker Process
            $workerPid = file_get_contents(WORKER_PID_FILE);
            if (\Swoole\Process::kill($workerPid, 0)) {
                $cliToWorkerPipeFile = CLI_TO_WORKER_PIPE;
                $pipe = @fopen($cliToWorkerPipeFile, 'w+');
                if (flock($pipe, LOCK_EX)) {
                    fwrite($pipe, $pipeMsg);
                    flock($pipe, LOCK_UN);
                }
                fclose($pipe);
            }
            sleep(3);
            $this->serverStop($appName);
        }
    }
}