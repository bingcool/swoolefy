<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\Application\ServerLifecycleManager;
use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\FifoPipeClient;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 发送消息命令。
 *
 * 通过 FIFO 管道向 WorkerService 的主 Worker 进程发送消息，
 * 并通过响应管道接收 Worker 的处理结果。
 *
 * 仅支持 WorkerService（Cron/Daemon/Script），普通 Swoole Server 不支持。
 *
 * 使用方式：
 *   php daemon.php send App --name=ProcessName --action=start --msg=xxx
 */
#[AsCommand(name: 'send')]
class SendCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('send message to the worker service')
             ->setHelp('<info>use php daemon.php send XXXXX --name=xxxxx --action=start</info>');
    }

    /**
     * 执行发送消息命令。
     *
     * 流程：
     * 1. 校验是否为 WorkerService
     * 2. 校验 PID 文件和进程状态
     * 3. 执行全局前置回调（start/restart 动作时）
     * 4. 创建响应管道监听 Worker 回复
     * 5. 通过管道发送消息到 Worker
     * 6. 等待响应并输出结果
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        // send 命令仅支持 WorkerService
        if (!isWorkerService()) {
            fmtPrintError("send command only for Worker,Cron Service, do not support cli application");
            return Command::SUCCESS;
        }

        $ctx = $this->context();
        $processName = getenv('name');
        $action = getenv('action');
        $msg = getenv('msg') ?: '';

        // 校验 PID 文件
        $pidFile = $ctx->pidFile;
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check the server whether running");
            return Command::FAILURE;
        }

        $masterPid = PidFileManager::read($pidFile);
        if ($masterPid <= 0) {
            fmtPrintError("Master Pid is invalid");
            return Command::FAILURE;
        }

        // start/restart 动作时执行全局前置回调
        global $beforeFunc;
        if (in_array($action, ['start', 'restart']) && isset($beforeFunc) && is_callable($beforeFunc)) {
            call_user_func($beforeFunc);
        }

        if (!ProcessTreeTerminator::isAlive($masterPid)) {
            return Command::SUCCESS;
        }

        // 创建响应管道，监听 Worker 回复
        $workerToCliPipe = $ctx->workerToCliPipe;
        if ($workerToCliPipe) {
            FifoPipeClient::listenResponse($workerToCliPipe, 5000, function (string $msg) {
                fmtPrintInfo($msg ?: '已向master进程发起跑脚本指令');
            });
        }

        // 通过管道发送消息到 Worker
        $manager = new ServerLifecycleManager();
        $manager->sendWorkerPipeMessage(
            $ctx,
            WORKER_CLI_SEND_MSG,
            $processName,
            json_encode(['action' => $action, 'msg' => $msg])
        );

        return Command::SUCCESS;
    }
}
