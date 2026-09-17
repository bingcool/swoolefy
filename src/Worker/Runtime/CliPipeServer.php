<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Worker\Runtime;

use RuntimeException;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\Dto\PipeMsgDtoWorker;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * CLI → Worker 的有名管道（FIFO）服务。
 *
 * 路径：`CLI_TO_WORKER_PIPE`（按 WORKER_SERVICE_NAME / --group 隔离，多实例互不抢管道）。
 * 顶层 action：
 * - STATUS：把 runtime 写回 targetHandler 指定的回传 FIFO；
 * - STOP：必须走 shutdownMainManager，只停子进程会让 timer/FIFO 在 macOS 上把 Master 挂住；
 * - RESTART：整机重启并回传 --group/--only；
 * - SEND_MSG：对单个 process_name 的 start/stop/restart/透传。
 *
 * unserialize 仅允许 PipeMsgDtoWorker。
 */
final class CliPipeServer
{
    /**
     * FIFO 读端 fd，关机时必须 Event::del + fclose，否则 EventLoop 不退出。
     *
     * @var resource|null
     */
    private $cliPipeFd;

    public function __construct(private MainManager $manager)
    {
    }

    /**
     * 创建 FIFO 并加入 EventLoop。已存在的同名管道先 unlink，避免读到上一次残留。
     *
     * @return bool|null false 表示未启用管道
     */
    public function install(): ?bool
    {
        if (!$this->manager->isCliPipeEnabled()) {
            return false;
        }

        $cliPipeFile = $this->manager->getCliToWorkerPipeFile();
        if (file_exists($cliPipeFile)) {
            @unlink($cliPipeFile);
        }

        if (!posix_mkfifo($cliPipeFile, 0777)) {
            throw new RuntimeException("Create Cli Pipe failed");
        }

        $this->cliPipeFd = fopen($cliPipeFile, 'w+');
        is_resource($this->cliPipeFd) && stream_set_blocking($this->cliPipeFd, false);
        \Swoole\Event::add($this->cliPipeFd, function () {
            try {
                $pipeMsg = fread($this->cliPipeFd, 8192);
                $cliPipeMsgDto = unserialize($pipeMsg, ['allowed_classes' => [PipeMsgDtoWorker::class]]);
                if ($cliPipeMsgDto instanceof PipeMsgDtoWorker) {
                    $this->dispatch($cliPipeMsgDto);
                }
            } catch (\Throwable $throwable) {
                $this->manager->handleWorkerException($throwable);
            }
        });

        return true;
    }

    /**
     * 分发一条已反序列化的 CLI 指令。单测可直接调此方法，不必真建 FIFO。
     */
    public function dispatch(PipeMsgDtoWorker $cliPipeMsgDto): void
    {
        switch ($cliPipeMsgDto->action) {
            case WORKER_CLI_STATUS:
                $this->manager->getStatusReporter()->masterStatusToCliFifoPipe($cliPipeMsgDto->targetHandler);
                break;
            case WORKER_CLI_STOP:
                // 不能只停子进程：FIFO 和状态 timer 会令 MainManager 在 macOS 上长期存活
                $this->manager->shutdownMainManager('cli-pipe');
                return;
            case WORKER_CLI_RESTART:
                $dateTime = date('Y-m-d H:i:s');
                $this->manager->logInfo("[{$dateTime}] 重启整个服务，所有进程将重启");
                $this->manager->getCommandHandler()->restartServerCommand();
                break;
            case WORKER_CLI_SEND_MSG:
                $this->dispatchProcessCommand($cliPipeMsgDto);
                break;
            default:
                break;
        }
    }

    /**
     * 关机第 4 步：从 EventLoop 摘掉 FIFO 并关闭 fd。
     */
    public function close(): void
    {
        if (is_resource($this->cliPipeFd)) {
            @\Swoole\Event::del($this->cliPipeFd);
            fclose($this->cliPipeFd);
            $this->cliPipeFd = null;
        }
    }

    /**
     * @return resource|null
     */
    public function getPipeFd()
    {
        return $this->cliPipeFd;
    }

    /**
     * status 上报里的 enable_cli_pipe 字段。
     */
    public function isPipeOpen(): bool
    {
        return is_resource($this->cliPipeFd);
    }

    /**
     * SEND_MSG 内层 action：start / restart / stop 指定进程，或把原文转给该进程全部 worker。
     *
     * START：parseLoadConf 为空（未知名或其它分组）则拒绝，防止跨组拉起污染本实例 confctl。
     */
    private function dispatchProcessCommand(PipeMsgDtoWorker $cliPipeMsgDto): void
    {
        $processName = $cliPipeMsgDto->targetHandler;
        $commands = $this->manager->getCommandHandler();
        $receiveMessage = json_decode($cliPipeMsgDto->message, true);
        $action = $receiveMessage['action'] ?? '';
        switch ($action) {
            case WORKER_CLI_START:
                if (!$this->registry()->hasList($processName)) {
                    $config = $commands->parseLoadConf($processName);
                    if (empty($config)) {
                        $this->manager->logError("找不到进程名【{$processName}】的配置项！");
                        $commands->responseMsgByPipe("找不到进程名【{$processName}】的配置项！");
                        return;
                    }
                    $commands->responseMsgByPipe("进程【{$processName}】已开始启动，请留意！");
                    $commands->startWorkerProcessCommand($config);
                } else {
                    if ($this->registry()->hasWorkerGroup($processName)) {
                        $commands->responseMsgByPipe("进程【{$processName}】已存在，请使用restart命令重启！");
                    }
                }
                break;
            case WORKER_CLI_RESTART:
                if ($this->registry()->hasWorkerGroup($processName)) {
                    $commands->responseMsgByPipe("进程【{$processName}】已开始重启，请留意！");
                    $commands->restartWorkerProcessCommand($processName);
                } else {
                    $config = $commands->parseLoadConf($processName);
                    if (empty($config)) {
                        $commands->responseMsgByPipe("找不到进程名【{$processName}】的配置项！");
                        return;
                    }
                    $commands->responseMsgByPipe("进程【{$processName}】已开始启动，请留意！");
                    $commands->startWorkerProcessCommand($config);
                }
                break;
            case WORKER_CLI_STOP:
                $commands->responseMsgByPipe("进程【{$processName}】开始逐步停止，请留意！");
                $commands->stopWorkerProcessCommand($processName);
                break;
            default:
                $workers = $this->registry()->getWorkersByName($processName);
                if ($workers !== null) {
                    $commands->responseMsgByPipe("子进程【{$processName}】已接收到指令，请留意！");
                    ksort($workers);
                    foreach ($workers as $process) {
                        /** @var AbstractBaseWorker $process */
                        $name = $process->getProcessName();
                        $workerId = $process->getProcessWorkerId();
                        $this->manager->writeByProcessName($name, $cliPipeMsgDto->message, $workerId);
                    }
                }
                break;
        }
    }

    /**
     * Registry 只读。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
