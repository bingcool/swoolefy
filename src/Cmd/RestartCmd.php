<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Swoolefy\Core\CommandRunner;

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
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $appName = $input->getArgument('app_name');
        $force   = $input->getOption('force');
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

        fmtPrintInfo("-----------正在重启进程中，请等待-----------");

        if (SystemEnv::isWorkerService()) {
            while (true) {
                if ($masterPid > 0 && \Swoole\Process::kill($masterPid, 0)) {
                    sleep(1);
                }else {
                    break;
                }
            }
        }else {
            sleep(1);
        }

        // send restart command to main worker process
        $phpBinFile = SystemEnv::PhpBinFile();
        $waitTime   = 15;
        if (SystemEnv::isWorkerService()) {
            // sleep max 30s
            $waitTime = 30;
        }

        if (str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD'])) {
            $selfFile = $_SERVER['SCRIPT_FILENAME'];
        }else {
            $selfFile = WORKER_START_SCRIPT_FILE;
        }

        $scriptFile = implode(' ',[$selfFile, 'start', $appName, '--daemon=1']);

        $runner = CommandRunner::getInstance('restart-'.time());
        $runner->isNextHandle(false);
        $runner->procOpen($phpBinFile, $scriptFile, [], function () {
        });

//        $runner = CommandRunner::getInstance('restart-'.time());
//        $runner->isNextHandle(false);
//        list($commandScript,) = $runner->exec($phpBinFile, $scriptFile, [],false,'/dev/null',false);
//        @exec($commandScript, $output);

        $time = time();
        while (true) {
            sleep(1);
            if (!file_exists($pidFile)) {
                if (time() - $time < $waitTime) {
                    continue;
                }
            }

            $newMasterPid = intval(file_get_contents($pidFile));
            // 新拉起的主进程id已经存在，说明新拉起的主进程已经启动成功
            if ($newMasterPid > 0 && $newMasterPid != $masterPid && \Swoole\Process::kill($newMasterPid, 0)) {
                if (SystemEnv::isWorkerService()) {
                    fmtPrintInfo("-----------进程重启完成------------");
                }
                if (SystemEnv::isWorkerService()) {
                    fmtPrintInfo("-----------看到此处，进程重启成功啦！重启成功啦！重启成功啦！------------");
                }else {
                    $this->serverStatus($appName, $pidFile);
                    fmtPrintInfo("-----------看到进程表格，进程重启成功啦！重启成功啦！重启成功啦！------------");
                }
                exit(0);
            }

            // wait time out
            if (time() - $time > $waitTime) {
                fmtPrintError("-----------无法确定是否重起成功，请使用 {$phpBinFile} {$selfFile} status {$appName} 查看进程是否启动成功!------------");
                exit(0);
            }
        }
    }

    /**
     * @param string $appName
     * @return void
     */
    protected function serverStop(string $appName)
    {
        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            return;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            fmtPrintInfo("Server has been stopped");
            return;
        }

        \Swoole\Process::kill($pid, SIGTERM);
        // if 'reload_async' => true,则默认workerStop有30s的过度期停顿这个时间稍微会比较长，设置成60过期
        $nowTime = time();
        fmtPrintInfo("Server begin to stopping at " . date("Y-m-d H:i:s") . ", pid={$pid}. please wait a moment...");
        while (true) {
            sleep(1);
            if (\Swoole\Process::kill($pid, 0) && (time() - $nowTime) > 10) {
                \Swoole\Process::kill($pid, SIGKILL);
                sleep(1);
            }

            if (!\Swoole\Process::kill($pid, 0)) {
                fmtPrintInfo("
        ---------------------stop info-------------------\n    
        Server Stop  OK. server stop at " . date("Y-m-d H:i:s")
                );
                @unlink($pidFile);
                break;
            } else {
                if ((time() - $nowTime) > 20) {
                    $exec = (new Exec())->run('pgrep -P ' . $pid);
                    $output = $exec->getOutput();
                    $managerProcessId = -1;
                    $workerProcessIds = [];
                    if (isset($output[0])) {
                        $managerProcessId = current($output);
                        $workerProcessIds = (new Exec())->run('pgrep -P ' . $managerProcessId)->getOutput();
                    }
                    foreach ([$pid, $managerProcessId, ...$workerProcessIds] as $processId) {
                        if ($processId > 0 && \Swoole\Process::kill($processId, 0)) {
                            \Swoole\Process::kill($processId, SIGKILL);
                        }
                    }
                    fmtPrintInfo("---------------------------stop info-----------------------");
                    fmtPrintInfo("Please use 'ps -ef | grep php-swoolefy' checkout swoole whether or not stop");
                    break;
                }
            }
        }
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
            $pipeMsgDto = new \Swoolefy\Worker\Dto\PipeMsgDto();
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