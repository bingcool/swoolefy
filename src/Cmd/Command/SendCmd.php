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
     * ===== FIFO 通信时序严格约束，绝对不能乱 =====
     *
     *   Step 1: prepareResponsePipe() — 创建响应 FIFO + 注册 Event 监听
     *   Step 2: sendWorkerPipeMessage() — 发送消息给 Worker
     *   Step 3: waitForResponse()     — 阻塞等待 Worker 响应
     *
     * 颠倒任何步骤都会导致通信失败（Worker 响应丢失或 CLI 无输出）。
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

        // ===== FIFO 通信时序（严格顺序，不能乱）=====
        // Step 1: 先创建响应 FIFO 并注册事件监听
        // !! 必须在发送消息之前完成 !!
        // 否则 Worker 处理完消息后尝试写入响应时，FIFO 尚未创建，响应将丢失
        $workerToCliPipe = $ctx->workerToCliPipe;
        $fifoClient = null;
        if ($workerToCliPipe) {
            $fifoClient = FifoPipeClient::prepareResponsePipe($workerToCliPipe, 5000, function (string $msg) {
                fmtPrintInfo($msg ?: '已向master进程发起跑脚本指令');
            });
        }

        // Step 2: 发送管道消息到 Worker
        $manager = new ServerLifecycleManager();
        $manager->sendWorkerPipeMessage(
            $ctx,
            WORKER_CLI_SEND_MSG,
            $processName,
            json_encode(['action' => $action, 'msg' => $msg])
        );

        // Step 3: 阻塞等待 Worker 响应
        // !! 必须在 Step 1 和 Step 2 之后调用 !!
        // Event::wait() 是阻塞调用，一旦进入就无法返回
        if ($fifoClient) {
            $fifoClient->waitForResponse();
        }

        return Command::SUCCESS;
    }
}
