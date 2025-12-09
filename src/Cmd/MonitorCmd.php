<?php
namespace Swoolefy\Cmd;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
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
        $this->setDescription('monitor the application weather stop')->setHelp('<info>use php cli.php monitor XXXXX or use php daemon.php monitor XXXXX</info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $appName    = $input->getArgument(self::APP_NAME);
        $pidFile    = $this->getPidFile($appName);
        // 人为执行执行stop命令后，会删除pidFile,防止监控不断重启进程。只有异常情况下的进程停止(包括CTRL+C停止)，pidFile依然会存在，不会被删除,这时monitor会监控重启。
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return 0;
        }

        $pid = intval(file_get_contents($pidFile));
        if (!\Swoole\Process::kill($pid, 0)) {
            sleep(5);
            if (!\Swoole\Process::kill($pid, 0)) {
                fmtPrintInfo("[CheckSever] server had shutdown, Now Restarting .....");
                fmtPrintInfo("PidFile={$pidFile}");
                // excel restart command.
                $appNameOption = self::APP_NAME;
                $daemonOption = join('', ["--", self::DAEMON]);
                $force = join('', ["--", self::FORCE]);
                $input = new ArrayInput([
                    'command' => "restart",
                    $appNameOption => $appName,
                    $daemonOption => 1,
                    $force => 1,
                ]);
                $output = new ConsoleOutput();
                $this->getApplication()->run($input, $output);
            }
            sleep(3);
            exit(0);
        } else {
            fmtPrintInfo(sprintf("[CheckSever] Server is Running, pid=%d", $pid));
        }
        return 0;
    }
}