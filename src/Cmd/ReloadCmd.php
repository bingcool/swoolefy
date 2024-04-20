<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
class ReloadCmd extends BaseCmd
{
    protected static $defaultName = 'reload';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('reload the application worker process')->setHelp('use php cli.php reload XXXXX');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (SystemEnv::isWorkerService()) {
            fmtPrintError("WorkerServer, CronService, ScriptService is not support reload command");
            return 0;
        }

        $appName = $input->getArgument('app_name');
        $pidFile = $this->getPidFile($appName);

        if (!is_file($pidFile)) {
            fmtPrintError("Pid file {$pidFile} is not exist, please check server is running");
            return 0;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            fmtPrintError("Pid={$pid} not exist");
            return 0;
        }

        // 发送信号，reload只针对worker进程
        \Swoole\Process::kill($pid, SIGUSR1);
        fmtPrintInfo("Server worker process begin to reload at " . date("Y-m-d H:i:s") . ". please wait a moment...");
        sleep(2);
        fmtPrintInfo("Server worker process reload successful at " . date("Y-m-d H:i:s"));
        return 0;
    }
}