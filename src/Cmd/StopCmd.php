<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stop',
)]
class StopCmd extends BaseCmd
{
    protected static $defaultName = 'stop';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('stop the application')->setHelp('use php cli.php stop XXXXX or php daemon.php stop XXXXX');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $appName = $input->getArgument('app_name');
        $force = $input->getOption('force');
        if (empty($force)) {
            if (!isWorkerService()) {
                echo "1、你确定停止应用【{$appName}】? (yes or no):";
            } else {
                echo "1、你确定停止workerService【" . WORKER_SERVICE_NAME . "】? (yes or no):";
            }
        }

        $lineValue = '';
        if (empty($force)) {
            while ($lineValue == '') {
                $lineValue = trim(fgets(STDIN));
            }
        }else {
            $lineValue = 'yes';
        }

        if (strtolower($lineValue) == 'yes') {
            if (!isWorkerService()) {
                $this->commonStop($appName);
            } else {
                $this->workerStop($appName);
            }
        } else {
            if (!isWorkerService()) {
                $this->info("\n你已放弃停止应用{$appName},应用继续running中");
            } else {
                $this->info("\n你已放弃停止workerService【" . WORKER_SERVICE_NAME . "】,应用继续running中");
            }
            exit(0);
        }

        return 0;
    }

    protected function commonStop($appName)
    {
        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            $this->error("Pid file={$pidFile} is not exist, please check the server whether running");
            exit(0);
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            $this->error("Server Stop!");
            exit(0);
        }

        \Swoole\Process::kill($pid, SIGTERM);
        // 如果'reload_async' => true,，则默认workerStop有30s的过度期停顿这个时间稍微会比较长，设置成60过期
        $nowTime = time();
        $this->info("Server begin to stopping at " . date("Y-m-d H:i:s") . ", pid={$pid}. please wait a moment...");
        while (true) {
            sleep(1);
            if (\Swoole\Process::kill($pid, 0) && (time() - $nowTime) > 10) {
                \Swoole\Process::kill($pid, SIGKILL);
                sleep(1);
            }

            if (!\Swoole\Process::kill($pid, 0)) {
                $this->info("
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
                    $this->info("---------------------------stop info-----------------------");
                    $this->info("Please use 'ps -ef | grep php-swoolefy' checkout swoole whether or not stop");
                    break;
                }
            }
        }
        \Swoole\Process::wait();
        exit(0);
    }

    protected function workerStop($appName)
    {
        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            $this->error("Pid file={$pidFile} is not exist, please check the server whether running");
            exit(0);
        }

        $masterPid = file_get_contents($pidFile);
        if (is_numeric($masterPid) && $masterPid > 0) {
            $masterPid = (int)$masterPid;
        } else {
            $this->error("Master Pid is invalid");
            exit(0);
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
            $this->commonStop($appName);
        }
    }
}