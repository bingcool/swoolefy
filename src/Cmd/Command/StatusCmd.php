<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\Infrastructure\FifoPipeClient;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Infrastructure\ServerStatusRenderer;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 查看服务状态命令。
 *
 * 支持两种模式：
 * - 普通 Swoole Server：展示 master/manager/worker 进程状态表格
 * - WorkerService：通过 FIFO 管道向 Worker 查询状态并展示响应
 *
 * 使用方式：
 *   php cli.php status App
 *   php daemon.php status App
 */
#[AsCommand(name: 'status')]
class StatusCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('Show status of the application')
             ->setHelp('<info>use php cli.php status XXXXX or php daemon.php status XXXXX</info>');
    }

    /**
     * 执行状态查询命令。
     *
     * 根据服务类型分发到不同的状态查询逻辑：
     * - WorkerService → workerStatus()（管道通信）
     * - 普通 Server → ServerStatusRenderer::render()（进程树遍历）
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        $ctx = $this->context();

        if ($ctx->isWorkerService) {
            $this->workerStatus($ctx);
        } else {
            ServerStatusRenderer::render($ctx->appName, $ctx->pidFile);
        }

        return Command::SUCCESS;
    }

    /**
     * WorkerService 状态查询。
     *
     * 通过 FIFO 管道向主 Worker 发送 WORKER_CLI_STATUS 消息，
     * Worker 处理后通过响应管道返回状态信息。
     *
     * ===== FIFO 通信时序严格约束，绝对不能乱 =====
     *
     *   Step 1: prepareResponsePipe() — 创建响应 FIFO + 注册 Event 监听
     *   Step 2: sleep(1) + fwrite()  — 等待注册完成后发送消息给 Worker
     *   Step 3: waitForResponse()    — 阻塞等待 Worker 响应
     *
     * 颠倒任何步骤都会导致通信失败（Worker 响应丢失或 CLI 无输出）。
     *
     * @param \Swoolefy\Cmd\DTO\CmdContext $ctx 命令上下文
     */
    protected function workerStatus(\Swoolefy\Cmd\DTO\CmdContext $ctx): void
    {
        $pidFile = $ctx->pidFile;

        // PID 文件不存在
        if (!is_file($pidFile)) {
            fmtPrintError("Pid file={$pidFile} is not exist, please check server weather is running");
            return;
        }

        $masterPid = PidFileManager::read($pidFile);
        if ($masterPid <= 0) {
            fmtPrintError("Master Worker Pid is invalid");
            return;
        }

        if (!ProcessTreeTerminator::isAlive($masterPid)) {
            fmtPrintError("Master Process of Pid={$masterPid} is not running");
            return;
        }

        $cliToWorkerPipe = $ctx->cliToWorkerPipe;
        $workerToCliPipe = $ctx->workerToCliPipe;

        // 校验 FIFO 管道
        if (!$cliToWorkerPipe || !file_exists($cliToWorkerPipe) || filetype($cliToWorkerPipe) !== 'fifo') {
            fmtPrintError(" Master Process is not enable cli pipe, so can not show status");
            return;
        }

        // 打开 CLI→Worker 管道（用于写入查询消息）
        $pipe = fopen($cliToWorkerPipe, 'r+');

        // 构造状态查询消息
        $pipeMsgDto = new \Swoolefy\Worker\Dto\PipeMsgDtoWorker();
        $pipeMsgDto->action = WORKER_CLI_STATUS;
        $pipeMsgDto->targetHandler = $workerToCliPipe;
        $pipeMsg = serialize($pipeMsgDto);

        // ===== FIFO 通信时序（严格顺序，不能乱）=====
        // Step 1: 先创建响应 FIFO 并注册事件监听
        // !! 必须在发送消息之前完成 !!
        // 否则 Worker 处理完消息后尝试写入响应时，FIFO 尚未创建，响应将丢失
        $fifoClient = null;
        if ($workerToCliPipe) {
            $fifoClient = FifoPipeClient::prepareResponsePipe($workerToCliPipe, 10000, function (string $msg) {
                fmtPrintInfo($msg);
            });
        }

        // Step 2: 等待事件循环注册完成后，再发送查询消息给 Worker
        // sleep(1) 确保 Swoole Event::add 已完成注册
        sleep(1);
        fwrite($pipe, $pipeMsg);
        fclose($pipe);

        // Step 3: 阻塞等待 Worker 响应（超时 10 秒）
        // !! 必须在 Step 1 和 Step 2 之后调用 !!
        // Event::wait() 是阻塞调用，一旦进入就无法返回
        if ($fifoClient) {
            $fifoClient->waitForResponse();
        }
    }
}
