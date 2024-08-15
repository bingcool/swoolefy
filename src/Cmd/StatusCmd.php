<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCmd extends BaseCmd
{
    protected static $defaultName = 'status';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('Show status of the application')->setHelp('use php cli.php status XXXXX or php daemon.php status XXXXX');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $appName = $input->getArgument('app_name');
        $pidFile = $this->getPidFile($appName);

        if (SystemEnv::isWorkerService()) {
            $this->workerStatus($pidFile);
        } else {
            $this->serverStatus($appName, $pidFile);
        }
        return 0;
    }

    /**
     * @param string $pidFile
     * @return void
     */
    protected function workerStatus(string $pidFile)
    {
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $masterPid = file_get_contents($pidFile);
        if (is_numeric($masterPid)) {
            $masterPid = (int)$masterPid;
        } else {
            fmtPrintError("Master Worker Pid is invalid");
            exit(0);
        }

        if (!\Swoole\Process::kill($masterPid, 0)) {
            fmtPrintError("Master Process of Pid={$masterPid} is not running");
            exit(0);
        }

        $cliToWorkerPipeFile = CLI_TO_WORKER_PIPE;
        $workerToCliPipeFile = WORKER_TO_CLI_PIPE;
        if (filetype($cliToWorkerPipeFile) != 'fifo' || !file_exists($cliToWorkerPipeFile)) {
            fmtPrintError(" Master Process is not enable cli pipe, so can not show status");
            exit(0);
        }

        $pipe = fopen($cliToWorkerPipeFile, 'r+');
        $pipeMsgDto = new \Swoolefy\Worker\Dto\PipeMsgDto();
        $pipeMsgDto->action = WORKER_CLI_STATUS;
        $pipeMsgDto->targetHandler = $workerToCliPipeFile;
        $pipeMsg = serialize($pipeMsgDto);
        if (file_exists($workerToCliPipeFile)) {
            unlink($workerToCliPipeFile);
        }

        // cli monitor worker response data msg
        posix_mkfifo($workerToCliPipeFile, 0777);
        $ctlPipe = fopen($workerToCliPipeFile, 'w+');
        stream_set_blocking($ctlPipe, false);
        \Swoole\Timer::after(10000, function () {
            \Swoole\Event::exit();
        });
        \Swoole\Event::add($ctlPipe, function () use ($ctlPipe) {
            $msg = fread($ctlPipe, 8192);
            fmtPrintInfo($msg);
        });
        sleep(1);
        // send to worker
        fwrite($pipe, $pipeMsg);
        \Swoole\Event::wait();
        fclose($ctlPipe);
        fclose($pipe);
        unlink($workerToCliPipeFile);
        exit(0);
    }
}