<?php
namespace Swoolefy\Cmd;

use Swoolefy\Core\Exec;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'monitor',
)]
class MonitorCmd extends BaseCmd
{
    protected static $defaultName = 'monitor';

    protected function configure()
    {
        parent::configure();
        $this->setDescription('monitor the application weather stop')->setHelp('use php cli.php monitor XXXXX or use php daemon.php monitor XXXXX');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selfScript = $_SERVER['argv'][0];
        $appName    = $input->getArgument('app_name');
        $pidFile    = $this->getPidFile($appName);
        // 人为执行stop命令后，会删除pidFile,防止监控不断重启进程。只有异常情况下的进程停止，pidFile会存在，不被删除，然后会监控判断是否需要重启
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return 0;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            sleep(5);
            if (!\Swoole\Process::kill($pid, 0)) {
                fmtPrintInfo("[CheckSever] server had shutdown, now restarting .....");
                // 重新启动
                $binFile = defined('PHP_BIN_FILE') ? PHP_BIN_FILE : '/usr/bin/php';
                $exec = new Exec();
                $exec->run("nohup {$binFile} {$selfScript} start {$appName} --daemon=1 > /dev/null 2>&1 &");
            }
            sleep(3);
            exit(0);
        }
        return 0;
    }
}