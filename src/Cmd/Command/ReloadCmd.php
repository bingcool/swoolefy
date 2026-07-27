<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 热重载 Worker 进程命令。
 *
 * 通过向 master 进程发送 SIGUSR1 信号，触发 Swoole 平滑重启所有 Worker 进程。
 * 注意：仅支持普通 Swoole Server，WorkerService 不支持 reload。
 *
 * 使用方式：
 *   php cli.php reload App
 */
#[AsCommand(name: 'reload')]
class ReloadCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('reload the application worker process')
             ->setHelp('<info>use php cli.php reload XXXXX</info>');
    }

    /**
     * 执行热重载命令。
     *
     * 流程：
     * 1. 校验是否为 WorkerService（不支持 reload）
     * 2. 读取 PID 文件，校验进程是否存活
     * 3. 发送 SIGUSR1 信号触发 Worker 平滑重启
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        // WorkerService（Cron/Daemon/Script）不支持 reload
        if (SystemEnv::isWorkerService()) {
            fmtPrintError("WorkerServer, CronService, ScriptService is not support reload command");
            return Command::SUCCESS;
        }

        $ctx = $this->context();
        $pidFile = $ctx->pidFile;

        // PID 文件不存在
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file {$pidFile} is not exist, please check the server if is running");
            return Command::SUCCESS;
        }

        $pid = PidFileManager::read($pidFile);

        // 进程不存在
        if ($pid <= 0 || !\Swoole\Process::kill($pid, 0)) {
            fmtPrintError("Pid={$pid} not exist");
            return Command::SUCCESS;
        }

        // 发送 SIGUSR1 信号，Swoole 会平滑重启所有 Worker 进程
        \Swoole\Process::kill($pid, SIGUSR1);
        fmtPrintInfo("Server worker process begin to reload at " . date("Y-m-d H:i:s") . ". please wait a moment...");
        sleep(2);
        fmtPrintInfo("Server worker process reload successful at " . date("Y-m-d H:i:s"));

        return Command::SUCCESS;
    }
}
