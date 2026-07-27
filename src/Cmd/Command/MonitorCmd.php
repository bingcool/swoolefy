<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 监控守护命令。
 *
 * 检查服务进程是否存活，若异常退出则自动触发 restart。
 * 典型使用场景：配合 crontab 定期执行，实现服务的自动恢复。
 *
 * 使用方式：
 *   php cli.php monitor App
 *   php daemon.php monitor App
 *
 * 判断逻辑：
 * - 人为执行 stop 命令后会删除 PID 文件，所以 monitor 不会触发重启
 * - 只有异常退出（PID 文件存在但进程已死亡）时才会自动重启
 */
#[AsCommand(name: 'monitor')]
class MonitorCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('monitor the application weather stop')
             ->setHelp('<info>use php cli.php monitor XXXXX or use php daemon.php monitor XXXXX</info>');
    }

    /**
     * 执行监控检查。
     *
     * 流程：
     * 1. 检查 PID 文件是否存在
     * 2. 检查进程是否存活
     * 3. 进程死亡时触发 restart（通过内部调用 restart 命令）
     * 4. 进程存活时输出状态信息
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        $appName = $input->getArgument(self::APP_NAME);
        $pidFile = $this->getPidFile($appName);

        // PID 文件不存在 → 可能是人为 stop，不触发重启
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return Command::SUCCESS;
        }

        $pid = (int) file_get_contents($pidFile);

        if (!ProcessTreeTerminator::isAlive($pid)) {
            // 二次确认：等待 5 秒后再检查（避免瞬时误判）
            sleep(5);
            if (!ProcessTreeTerminator::isAlive($pid)) {
                fmtPrintInfo("[CheckSever] server had shutdown, Now Restarting .....");
                fmtPrintInfo("PidFile={$pidFile}");

                // 内部调用 restart 命令（--force 跳过确认）
                $restartInput = new ArrayInput([
                    'command' => 'restart',
                    self::APP_NAME => $appName,
                    '--' . self::DAEMON => 1,
                    '--' . self::FORCE => 1,
                ]);
                $this->getApplication()->run($restartInput, new ConsoleOutput());
            }
            sleep(3);
            return Command::SUCCESS;
        }

        // 进程存活
        fmtPrintInfo(sprintf("[CheckSever] Server is Running, pid=%d", $pid));
        return Command::SUCCESS;
    }
}
