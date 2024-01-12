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
        $selfScript = START_DIR_ROOT.'/'.$_SERVER['argv'][0];
        $appName = $input->getArgument('app_name');
        $pidFile = $this->getPidFile($appName);
        if (!is_file($pidFile)) {
            $this->error("Pid file={$pidFile} is not exist, please check server weather is running");
             return 0;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            sleep(2);
            if (!\Swoole\Process::kill($pid, 0)) {
                $this->info("【CheckSever】 server had shutdown, now restarting .....");
                // 重新启动
                $exec = new Exec();
                $exec->run("/usr/bin/php {$selfScript} start {$appName} --daemon=1");
            }
        }
        return 0;
    }
}