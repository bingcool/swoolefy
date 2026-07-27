<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Command;

use Swoolefy\Cmd\Application\ServerLifecycleManager;
use Swoolefy\Cmd\BaseCmd;
use Swoolefy\Cmd\DTO\StopStatus;
use Swoolefy\Cmd\Infrastructure\CmdLogWriter;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 停止服务命令。
 *
 * 支持两种模式：
 * - 普通 Swoole Server（HTTP/WebSocket/RPC/UDP/MQTT）：SIGTERM → 轮询 → SIGKILL
 * - WorkerService（Cron/Daemon/Script）：管道通知 Worker → 再停止 Server
 *
 * 使用方式：
 *   php cli.php stop App
 *   php cli.php stop App --force=1   # 跳过交互确认
 *
 * 改造说明：
 * - 核心停止逻辑委托给 ServerLifecycleManager
 * - 使用 StopResult + StopStatus enum 替代字符串比较
 * - exit(0) 改为 return Command::SUCCESS/FAILURE
 */
#[AsCommand(name: 'stop')]
class StopCmd extends BaseCmd
{
    protected function configure()
    {
        parent::configure();
        $this->setDescription('stop the application')
             ->setHelp('<info>use php cli.php stop XXXXX or php daemon.php stop XXXXX</info>');
    }

    /**
     * 执行停止命令。
     *
     * 流程：
     * 1. 用户确认（除非 --force）
     * 2. 委托 ServerLifecycleManager 执行停止
     * 3. 根据 StopResult 输出结果并返回退出码
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hasInitError()) {
            return Command::FAILURE;
        }

        $ctx = $this->context();

        // 用户交互确认
        if (!$this->confirmStop($ctx->appName, $input->getOption(self::FORCE))) {
            $this->printCancelMessage($ctx->appName);
            return Command::SUCCESS;
        }

        try {
            $manager = new ServerLifecycleManager();

            // 根据服务类型选择不同的停止策略
            $result = $ctx->isWorkerService
                ? $manager->stopWorkerService($ctx)
                : $manager->stopServer($ctx);

            // 写入操作日志
            CmdLogWriter::write("停止服务: " . ($ctx->isWorkerService
                ? (defined('WORKER_SERVICE_NAME') ? WORKER_SERVICE_NAME : $ctx->appName)
                : $ctx->appName));

            // 根据结果输出对应信息
            if ($result->isSuccessful()) {
                return Command::SUCCESS;
            }

            // 非成功状态（超时、PID不存在等）
            fmtPrintError($result->message);
            return Command::FAILURE;

        } catch (\Throwable $e) {
            fmtPrintError("Stop failed: " . $e->getMessage());
            CmdLogWriter::write("停止服务失败: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * 确认停止操作。
     *
     * 若指定了 --force 选项则跳过确认，否则通过 SymfonyStyle 交互询问。
     *
     * @param string $appName 应用名称
     * @param mixed  $force   --force 选项值
     * @return bool 是否确认停止
     */
    private function confirmStop(string $appName, mixed $force): bool
    {
        if (!empty($force)) {
            return true;
        }

        $prompt = SystemEnv::isWorkerService()
            ? "你确定 [停止] workerService【" . WORKER_SERVICE_NAME . "】? (yes or no)"
            : "你确定 [停止] 应用【{$appName}】? (yes or no)";

        $lineValue = initConsoleStyleIo()->ask($prompt);
        return strtolower($lineValue) === 'yes';
    }

    /**
     * 打印取消停止的消息。
     *
     * @param string $appName 应用名称
     */
    private function printCancelMessage(string $appName): void
    {
        $message = SystemEnv::isWorkerService()
            ? "你已放弃停止workerService【" . WORKER_SERVICE_NAME . "】,应用继续running中"
            : "你已放弃停止应用{$appName},应用继续running中";

        fmtPrintInfo(PHP_EOL . $message);
    }
}
