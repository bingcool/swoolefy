<?php
namespace Swoolefy\Cmd;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SendCmd extends BaseCmd
{
    protected static $defaultName = 'send';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('send message to the worker service')->setHelp('use php daemon.php send XXXXX --name=xxxxx --action=start');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!isWorkerService()) {
            fmtPrintError("send command only for Worker,Cron Service, do not support cli application");
            return 0;
        }

        $appName     = $input->getArgument('app_name');
        $processName = getenv('name');
        $action      = getenv('action');
        $msg         = getenv('msg');
        if (empty($msg)) {
            $msg = '';
        }

        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check the server whether running");
            exit(0);
        }

        $masterPid = file_get_contents($pidFile);
        if (is_numeric($masterPid) && $masterPid > 0) {
            $masterPid = (int)$masterPid;
        } else {
            fmtPrintError("Master Pid is invalid");
            exit(0);
        }

        global $beforeFunc;
        if (in_array($action, ['start','restart']) && isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        if (\Swoole\Process::kill($masterPid, 0)) {
            $pipeMsgDto = new \Swoolefy\Worker\Dto\PipeMsgDto();
            $pipeMsgDto->action = WORKER_CLI_SEND_MSG;
            $pipeMsgDto->targetHandler = $processName;
            $pipeMsgDto->message = json_encode([
                'action' => $action,
                'msg' => $msg
            ]);

            // cli终端Event::add监控数据就绪返回
            $workerToCliPipeFile = WORKER_TO_CLI_PIPE;
            if (file_exists($workerToCliPipeFile)) {
                unlink($workerToCliPipeFile);
            }
            posix_mkfifo($workerToCliPipeFile, 0777);
            $ctlPipe = fopen($workerToCliPipeFile, 'w+');
            stream_set_blocking($ctlPipe, false);
            \Swoole\Timer::after(5000, function () {
                \Swoole\Event::exit();
            });
            \Swoole\Event::add($ctlPipe, function () use ($ctlPipe) {
                $msg = fread($ctlPipe, 8192);
                if (empty($msg)) {
                    $msg = '已向master进程发起跑脚本指令';
                }
                fmtPrintInfo($msg);
                \Swoole\Event::exit();
            });

            // send msg to Worker
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

            \Swoole\Event::wait();
            fclose($ctlPipe);
            unlink($workerToCliPipeFile);
            exit(0);
        }

        return 0;
    }
}