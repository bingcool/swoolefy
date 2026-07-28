<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Application;

use Swoolefy\Cmd\DTO\CmdContext;
use Swoolefy\Cmd\DTO\StopResult;
use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Swoolefy\Worker\Dto\PipeMsgDtoWorker;

/**
 * 统一的服务生命周期管理器。
 *
 * 职责：封装服务停止、进程校验、PID 操作等生命周期管理逻辑。
 * StopCmd / RestartCmd / MonitorCmd 共用此类，消除原先散落在各 Command 中的重复代码。
 *
 * 设计原则：
 * - 接收 CmdContext 作为输入，不直接读取全局常量
 * - 返回 StopResult 值对象，调用方通过 enum 判断结果
 * - 无状态，所有方法均为纯函数（副作用仅限进程信号和文件操作）
 */
final class ServerLifecycleManager
{
    /** 轮询间隔（秒）：每次检查进程是否已退出 */
    private const SLEEP_INTERVAL_SECOND = 1;

    /** 默认强制杀死超时（秒）：超过此时间发送 SIGKILL */
    private const DEFAULT_KILL_TIMEOUT = 10;

    /** 默认总停止超时（秒）：超过此时间认为停止失败 */
    private const DEFAULT_STOP_TIMEOUT = 20;

    /**
     * 停止普通 Swoole Server（HTTP/WebSocket/RPC/UDP/MQTT）。
     *
     * 停止策略：
     * 1. 发送 SIGTERM 给 master 进程，触发优雅停机
     * 2. 轮询等待 master 进程退出
     * 3. 超过 kill_timeout 后发送 SIGKILL 强制杀死整个进程树
     * 4. 超过 stop_timeout 后返回超时结果
     *
     * @param CmdContext $ctx 命令上下文
     * @return StopResult 停止操作结果
     */
    public function stopServer(CmdContext $ctx): StopResult
    {
        $pidFile = $ctx->pidFile;
        if (!$pidFile || !is_file($pidFile)) {
            return StopResult::pidFileNotFound($pidFile ?? '');
        }

        $masterPid = PidFileManager::read($pidFile);
        if ($masterPid <= 0) {
            return StopResult::invalidPid($pidFile);
        }

        // 进程已不存在 → 服务已停止
        if (!ProcessTreeTerminator::isAlive($masterPid)) {
            PidFileManager::remove($pidFile);
            return StopResult::alreadyStopped();
        }

        // 解析动态超时配置（支持 WebSocket/MQTT graceful_shutdown）
        $timeouts = $this->resolveStopTimeouts($ctx);

        // 发送 SIGTERM，触发 Swoole 优雅停机流程
        \Swoole\Process::kill($masterPid, SIGTERM);
        fmtPrintInfo(sprintf(
            "[%s] Server begin to stopping at %s, pid=%d. Please wait a moment...",
            $ctx->appName,
            date("Y-m-d H:i:s"),
            $masterPid
        ));

        // 轮询等待进程退出
        $startTime = time();
        while (true) {
            sleep(self::SLEEP_INTERVAL_SECOND);

            // 进程已退出 → 成功
            if (!ProcessTreeTerminator::isAlive($masterPid)) {
                PidFileManager::remove($pidFile);
                fmtPrintNote("---------------------stop info-------------------");
                fmtPrintNote(sprintf(
                    "【%s】 Server Stopped Successfully at %s",
                    $ctx->appName,
                    date("Y-m-d H:i:s")
                ));
                return StopResult::success($masterPid);
            }

            // 超过 kill_timeout → 强制杀死进程树
            if ((time() - $startTime) > $timeouts['kill']) {
                $this->forceKillAll($ctx);
                sleep(self::SLEEP_INTERVAL_SECOND);
            }

            // 超过 stop_timeout → 返回超时
            if ((time() - $startTime) > $timeouts['stop']) {
                $this->forceKillAll($ctx);
                fmtPrintNote("Stop timeout reached. Force killing remaining processes.");
                fmtPrintNote("Please use 'ps -ef | grep php-swoolefy' to check if processes are stopped");
                return StopResult::timeout($masterPid);
            }
        }
    }

    /**
     * 停止 WorkerService（Cron/Daemon/Script）。
     *
     * 停止策略：
     * 1. 先通过 FIFO 管道通知 Worker 进程停止（WORKER_CLI_STOP）
     * 2. 再停止 Server 主进程（复用 stopServer 逻辑）
     * 3. 按 Worker PID 单独确认 MainManager 已退出，超时后强制清理
     *
     * @param CmdContext $ctx 命令上下文
     * @return StopResult 停止操作结果
     */
    public function stopWorkerService(CmdContext $ctx): StopResult
    {
        $serverPid = ($ctx->pidFile !== '' && is_file($ctx->pidFile))
            ? PidFileManager::read($ctx->pidFile)
            : 0;
        $workerMasterPid = ($ctx->workerPidFile !== '' && is_file($ctx->workerPidFile))
            ? PidFileManager::read($ctx->workerPidFile)
            : 0;

        $serverAlive = $serverPid > 0 && ProcessTreeTerminator::isAlive($serverPid);
        $workerMasterAlive = $workerMasterPid > 0 && ProcessTreeTerminator::isAlive($workerMasterPid);
        if (!$serverAlive && !$workerMasterAlive) {
            PidFileManager::remove($ctx->pidFile);
            PidFileManager::remove($ctx->workerPidFile);

            return StopResult::alreadyStopped();
        }

        // 优先通过 FIFO 触发 MainManager 自身的完整关闭流程（子进程 → timer/FIFO → EventLoop → self exit）。
        $notified = $workerMasterAlive && $this->sendWorkerPipeMessage($ctx, WORKER_CLI_STOP);
        if ($workerMasterAlive && !$notified) {
            // FIFO 不可用时直接通知 Worker Master；SIGTERM 与 FIFO 共用 shutdownMainManager()。
            \Swoole\Process::kill($workerMasterPid, SIGTERM);
        }

        // 外层 Swoole Server 与 Worker Master 是两个需要分别确认的进程。
        $serverResult = $serverAlive ? $this->stopServer($ctx) : StopResult::alreadyStopped();

        if (!$this->waitWorkerMasterStopped($ctx, $workerMasterPid, 20)) {
            return StopResult::timeout($workerMasterPid);
        }

        return $serverResult;
    }

    /**
     * 等待 Worker Master 退出；超时后先 SIGTERM，再按 PID 树 SIGKILL 兜底。
     *
     * macOS 上外层 Swoole Server 退出后，Worker Master 可能被 re-parent 到 PID 1，
     * 因此不能只依赖外层 Server PID 树或进程标题匹配。
     */
    private function waitWorkerMasterStopped(CmdContext $ctx, int $workerMasterPid, int $timeoutSeconds): bool
    {
        if ($workerMasterPid <= 0) {
            PidFileManager::remove($ctx->workerPidFile);

            return true;
        }

        $deadline = microtime(true) + max(1, $timeoutSeconds);
        while (microtime(true) < $deadline) {
            if (!ProcessTreeTerminator::isAlive($workerMasterPid)) {
                PidFileManager::remove($ctx->workerPidFile);

                return true;
            }
            usleep(100_000);
        }

        // MainManager 未按期退出：直接按记录的 Worker PID 处理，不依赖 PPID 或 process title。
        \Swoole\Process::kill($workerMasterPid, SIGTERM);
        usleep(500_000);
        if (ProcessTreeTerminator::isAlive($workerMasterPid)) {
            ProcessTreeTerminator::killProcessTree($workerMasterPid);
            usleep(500_000);
        }

        $stopped = !ProcessTreeTerminator::isAlive($workerMasterPid);
        if ($stopped) {
            PidFileManager::remove($ctx->workerPidFile);
        }

        return $stopped;
    }

    /**
     * 向 WorkerService 发送管道消息。
     *
     * 通过 FIFO 管道（CLI_TO_WORKER_PIPE）向主 Worker 进程发送序列化消息。
     * 使用 flock 保证多进程并发写入时的消息完整性。
     *
     * @param CmdContext $ctx            命令上下文（含 cliToWorkerPipe 路径）
     * @param string     $action         消息动作（如 WORKER_CLI_STOP, WORKER_CLI_SEND_MSG）
     * @param string     $targetHandler  目标处理器名称
     * @param string     $message        附加消息内容
     * @return bool 发送是否成功
     */
    public function sendWorkerPipeMessage(
        CmdContext $ctx,
        string $action,
        string $targetHandler = '',
        string $message = ''
    ): bool {
        $cliToWorkerPipe = $ctx->cliToWorkerPipe;
        if (!$cliToWorkerPipe) {
            return false;
        }

        // 校验 Worker 进程是否存活
        $workerPid = 0;
        if ($ctx->workerPidFile && is_file($ctx->workerPidFile)) {
            $workerPid = PidFileManager::read($ctx->workerPidFile);
        }
        if ($workerPid <= 0 || !ProcessTreeTerminator::isAlive($workerPid)) {
            return false;
        }

        // 构造管道消息 DTO
        $pipeMsgDto = new PipeMsgDtoWorker();
        $pipeMsgDto->action = $action;
        $pipeMsgDto->targetHandler = $targetHandler;
        $pipeMsgDto->message = $message;
        $pipeMsg = serialize($pipeMsgDto);

        // 打开 FIFO 管道写入消息（使用排他锁保证原子性）
        $pipe = @fopen($cliToWorkerPipe, 'w+');
        if ($pipe === false) {
            return false;
        }

        try {
            if (flock($pipe, LOCK_EX)) {
                fwrite($pipe, $pipeMsg);
                flock($pipe, LOCK_UN);
            }
            return true;
        } finally {
            fclose($pipe);
        }
    }

    /**
     * 动态解析 stop 超时配置。
     *
     * 支持 WebSocket / MQTT 的 graceful_shutdown 配置：
     * - 从 Protocol/conf.php 读取 max_wait_time
     * - 从 Config/websocket.php 或 Protocol/conf.php 读取 graceful_shutdown.drain_timeout
     * - 若启用 graceful_shutdown，则 kill_timeout = drain_timeout + ceil(max_wait_time/2)
     *   stop_timeout = drain_timeout + max_wait_time + 5
     *
     * @param CmdContext $ctx 命令上下文
     * @return array{kill: int, stop: int} kill 超时和 stop 超时（秒）
     */
    public function resolveStopTimeouts(CmdContext $ctx): array
    {
        $kill = self::DEFAULT_KILL_TIMEOUT;
        $stop = self::DEFAULT_STOP_TIMEOUT;
        $maxWait = 10;
        $gracefulEnabled = false;
        $drainTimeout = 30;

        try {
            $config = $ctx->config;
            $maxWait = max(1, (int) ($config['setting']['max_wait_time'] ?? $maxWait));

            // WebSocket graceful_shutdown 配置（Config/websocket.php）
            $websocketFile = $ctx->appPath . '/Config/websocket.php';
            if (is_file($websocketFile)) {
                $websocket = (array) include $websocketFile;
                $gs = $websocket['graceful_shutdown'] ?? [];
                if (is_array($gs) && !empty($gs['enable'])) {
                    $gracefulEnabled = true;
                    $drainTimeout = max(1, (int) ($gs['drain_timeout'] ?? 30));
                }
            }

            // MQTT graceful_shutdown 配置（Protocol/conf.php 中）
            if (!$gracefulEnabled) {
                $gs = $config['graceful_shutdown'] ?? [];
                if (is_array($gs) && !empty($gs['enable'])) {
                    $gracefulEnabled = true;
                    $drainTimeout = max(1, (int) ($gs['drain_timeout'] ?? 30));
                }
            }
        } catch (\Throwable) {
            // 配置读取失败时使用默认值
        }

        // graceful_shutdown 启用时，延长超时以等待 drain 完成
        if ($gracefulEnabled) {
            $kill = $drainTimeout + max(1, (int) ceil($maxWait / 2));
            $stop = $drainTimeout + $maxWait + 5;
            fmtPrintInfo(sprintf(
                "[ServerLifecycle] graceful stop timeouts: force_kill=%ds total_wait=%ds (drain=%ds max_wait_time=%ds)",
                $kill,
                $stop,
                $drainTimeout,
                $maxWait
            ));
        }

        return [
            'kill' => max(self::DEFAULT_KILL_TIMEOUT, $kill),
            'stop' => max(self::DEFAULT_STOP_TIMEOUT, $stop),
        ];
    }

    /**
     * 强制杀死整个服务进程树。
     *
     * 作为最后手段使用：
     * 1. 通过进程树递归杀死所有子进程（killProcessTree）
     * 2. 按进程名杀死 master 和 manager（killByProcessTitle）
     *
     * @param CmdContext $ctx 命令上下文
     */
    private function forceKillAll(CmdContext $ctx): void
    {
        $processNames = $ctx->processNames();

        // 先按 PID 树杀（保证覆盖所有子进程）
        if ($ctx->pid > 0) {
            ProcessTreeTerminator::killProcessTree($ctx->pid);
        }

        // 再按进程名杀（兜底，处理 PID 树未覆盖的情况）
        ProcessTreeTerminator::killByProcessTitle($processNames['master']);
        ProcessTreeTerminator::killByProcessTitle($processNames['manager']);
    }
}
