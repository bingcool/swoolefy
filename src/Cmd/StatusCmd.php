<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Helper\TableStyle;
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

        if (SystemEnv::isWorkerService()) {
            $this->workerStatus($pidFile);
        } else {
            $this->commonStatus($appName, $pidFile);
        }
        return 0;
    }

    protected function commonStatus($appName, $pidFile)
    {
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            fmtPrintError("Server Maybe Shutdown, You can use 'ps -ef | grep php-swoolefy' ");
            return;
        }

        $exec = (new Exec())->run('pgrep -P ' . $pid);
        $output = $exec->getOutput();
        $managerProcessId = -1;
        $workerProcessIds = [];
        if (isset($output[0])) {
            $managerProcessId = current($output);
            $workerProcessIds = (new Exec())->run('pgrep -P ' . $managerProcessId)->getOutput();
        }

        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $table  = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['进程名称', '进程ID','父进程ID', '进程状态']);
        $table->setRows(array(
            array('master process', $pid,'--','running'),
            array('manager process', $managerProcessId, $pid, 'running')
        ));

        foreach ($workerProcessIds as $id=>$processId) {
            $table->addRow(array("worker process-{$id}", $processId, $managerProcessId, 'running'));
        }

        $tableStyle = new TableStyle();
        $tableStyle->setCellRowFormat('<info>%s</info>');
        $table->setStyle($tableStyle);

        $table->render();
    }

    protected function workerStatus($pidFile)
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