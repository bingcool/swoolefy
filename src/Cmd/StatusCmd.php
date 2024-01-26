<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\BaseServer;
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $appName = $input->getArgument('app_name');
        $pidFile = $this->getPidFile($appName);

        if (!SystemEnv::isWorkerService()) {
            $this->commonStatus($appName, $pidFile);
        } else {
            $this->workerStatus($pidFile);
        }
        return 0;
    }

    protected function commonStatus($appName, $pidFile)
    {
        if (!is_file($pidFile)) {
            $this->error("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            $this->error("Server Maybe Shutdown, You can use 'ps -ef | grep php-swoolefy' ");
            return;
        }

        $exec = 'ps -ef | grep php | grep ' . BaseServer::getAppPrefix() . ' | grep -v grep';

        exec($exec, $output, $return);

        if (empty($output)) {
            $this->info("'ps -ef' not match {$appName}-swoolefy");
            return;
        }

        foreach ($output as $value) {
            $this->info(
                trim($value)
            );
        }
    }

    protected function workerStatus($pidFile)
    {
        if (!is_file($pidFile)) {
            $this->error("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $masterPid = file_get_contents($pidFile);
        if (is_numeric($masterPid)) {
            $masterPid = (int)$masterPid;
        } else {
            $this->error("Master Worker Pid is invalid");
            exit(0);
        }

        if (!\Swoole\Process::kill($masterPid, 0)) {
            $this->error("Master Process of Pid={$masterPid} is not running");
            exit(0);
        }

        $cliToWorkerPipeFile = CLI_TO_WORKER_PIPE;
        $workerToCliPipeFile = WORKER_TO_CLI_PIPE;
        if (filetype($cliToWorkerPipeFile) != 'fifo' || !file_exists($cliToWorkerPipeFile)) {
            $this->error(" Master Process is not enable cli pipe, so can not show status");
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

        // cli监控worker返回数据
        posix_mkfifo($workerToCliPipeFile, 0777);
        $ctlPipe = fopen($workerToCliPipeFile, 'w+');
        stream_set_blocking($ctlPipe, false);
        \Swoole\Timer::after(10000, function () {
            \Swoole\Event::exit();
        });
        \Swoole\Event::add($ctlPipe, function () use ($ctlPipe) {
            $msg = fread($ctlPipe, 8192);
            $this->info($msg);
        });

        sleep(1);
        // 向worker发送数据
        fwrite($pipe, $pipeMsg);
        \Swoole\Event::wait();
        fclose($ctlPipe);
        fclose($pipe);
        unlink($workerToCliPipeFile);
        exit(0);
    }
}