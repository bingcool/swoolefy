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

use Swoolefy\Core\Memory\SysvmsgManager;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * 信号安装与优雅关机。
 *
 * `$isExit` 只在本类写入：createDynamicProcess 通过 isMasterExiting() 读取，关机中禁止扩容。
 * 信号约定：SIGINT 由外层 Swoole Master 处理，本进程只收 SIGHUP/SIGTERM，避免前台进程组抢 SIGINT。
 * SIGUSR2 = 通知全部子进程 reboot（不退出 Master）；SIGCHLD 在 Supervisor。
 */
final class SignalShutdown
{
    /**
     * 关机重入开关。true 后 shutdown() 立即返回。
     */
    private bool $isExit = false;

    /**
     * 业务自定义信号（禁止覆盖 SIGTERM/SIGUSR1/SIGUSR2/SIGCHLD）。
     *
     * @var array<int, array{0:int,1:callable}>
     */
    private array $signal = [];

    /**
     * 真正做 FIFO/SysV/信号卸载的闭包，由 shutdown() 在等子进程退出后调用。
     *
     * @var \Closure|null
     */
    private $onRegisterShutdownFunction;

    public function __construct(protected MainManager $manager)
    {
    }

    /**
     * Master 是否已进入退出流程（动态扩容、IPC 动作需检查）。
     */
    public function isExiting(): bool
    {
        return $this->isExit;
    }

    /**
     * SIGUSR2 reload 前清退出标记，允许 reload 期间再次扩容。
     */
    public function setExiting(bool $exiting): void
    {
        $this->isExit = $exiting;
    }

    /**
     * 注册自定义信号。框架已占用的信号静默忽略，防止把 SIGCHLD 回收逻辑冲掉。
     */
    public function addSignal(int $signal, callable $function): void
    {
        if (!in_array($signal, [SIGTERM, SIGUSR2, SIGUSR1, SIGCHLD])) {
            $this->signal[$signal] = [$signal, $function];
        }
    }

    /**
     * 主进程停止信号。Ctrl+C 的 SIGINT 由外层 Swoole Master 处理，这里只绑 SIGHUP/SIGTERM。
     */
    public function installStopSignal(): void
    {
        $handler = $this->signalHandle();
        \Swoole\Process::signal(SIGHUP, $handler);
        \Swoole\Process::signal(SIGTERM, $handler);
    }

    /**
     * SIGUSR2：通知全部子进程 reboot。子进程应在 wait_time 内停止接新任务（isRebooting/isExiting）。
     */
    public function installReloadSignal(): void
    {
        \Swoole\Process::signal(SIGUSR2, function ($signo) {
            $this->isExit = false;
            foreach ($this->registry()->allWorkers() as $processes) {
                foreach ($processes as $workerId => $process) {
                    $processName = $process->getProcessName();
                    $this->manager->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_REBOOT_FLAG, $workerId);
                }
            }
        });
    }

    /**
     * 安装 addSignal() 登记的自定义信号。
     */
    public function installCustomSignals(): void
    {
        if (!empty($this->signal)) {
            foreach ($this->signal as $signalInfo) {
                list($signal, $function) = $signalInfo;
                try {
                    \Swoole\Process::signal($signal, $function);
                } catch (\Throwable $throwable) {
                    $this->manager->handleWorkerException($throwable);
                }
            }
        }
    }

    /**
     * 准备关机清理闭包：关 FIFO、销毁 SysV 队列、卸信号。仅 Master 环境安装。
     */
    public function installRegisterShutdownFunction(): void
    {
        if (!$this->manager->isInMasterProcessEnv()) {
            return;
        }

        $this->onRegisterShutdownFunction = function () {
            try {
                is_callable($this->manager->onExit) && $this->manager->onExit->call($this->manager);
            } catch (\Throwable $throwable) {
                $this->manager->handleWorkerException($throwable);
            } finally {
                $this->manager->getCliPipeServer()->close();
                $sysvmsgManager = SysvmsgManager::getInstance();
                $sysvmsgManager->destroyMsgQueue();
                unset($sysvmsgManager);
                @\Swoole\Process::signal(SIGUSR1, null);
                @\Swoole\Process::signal(SIGUSR2, null);
                @\Swoole\Process::signal(SIGINT, null);
                @\Swoole\Process::signal(SIGHUP, null);
                @\Swoole\Process::signal(SIGTERM, null);
            }
            $this->manager->logInfo("终端关闭，master进程stop, worker_master_pid={$this->manager->getMasterPid()}");
        };
    }

    /**
     * 唯一退出实现（MainManager::shutdownMainManager 委托到这里）。
     *
     * 顺序不得打乱（macOS 上 FIFO/timer 残留会导致 Master 假死）：
     * 1. 置 $isExit，拒绝重入；
     * 2. 通知全部业务子进程 EXIT；
     * 3. waitWorkersExitOrKill（预算 = max(wait_time+maxWaitTimeOfExit)+margin）；
     * 4. 清 status timer、关 FIFO、删属于本实例的 PID 文件（比对文件内 pid，防误删新实例）；
     * 5. Event::exit + process->exit(0)。
     */
    public function shutdown(string $source): void
    {
        if ($this->isExit) {
            return;
        }
        $this->isExit = true;
        $this->manager->logInfo("MainManager begin shutdown, source={$source}, pid={$this->manager->getMasterPid()}");

        try {
            $this->manager->getCommandHandler()->stopAllWorkerProcessCommand();
        } catch (\Throwable $throwable) {
            $this->handleShutdownException($throwable);
        }

        try {
            $this->waitWorkersExitOrKill($this->resolveShutdownWaitSeconds());
        } catch (\Throwable $throwable) {
            $this->handleShutdownException($throwable);
        }

        $this->manager->getStatusReporter()->clearTimer();

        if (is_callable($this->onRegisterShutdownFunction)) {
            try {
                call_user_func($this->onRegisterShutdownFunction);
            } catch (\Throwable $throwable) {
                $this->handleShutdownException($throwable);
            }
        }

        if (defined('WORKER_PID_FILE') && is_file(WORKER_PID_FILE)) {
            $pidInFile = (int) trim((string) @file_get_contents(WORKER_PID_FILE));
            if ($pidInFile === (int) $this->manager->getMasterPid()) {
                @unlink(WORKER_PID_FILE);
            }
        }

        $this->manager->logInfo("MainManager shutdown finished, source={$source}, pid={$this->manager->getMasterPid()}");

        try {
            $processInstance = AbstractProcess::getProcessInstance();
            $process = $processInstance->getProcess();
            if (method_exists($processInstance, '__destruct') && version_compare(phpversion(), '8.0.0', '>=')) {
                $processInstance->__destruct();
            }
            @\Swoole\Event::del($process->pipe);
            \Swoole\Event::exit();
            $process->exit(0);
        } catch (\Throwable $throwable) {
            $this->handleShutdownException($throwable);
            \Swoole\Event::exit();
            exit(0);
        }
    }

    /**
     * Master 优雅退出等待上限：各 Worker 的 wait_time + maxWaitTimeOfExit 取 max，再加清理余量。
     * 无 Worker 时回退 10+30+margin，不得短于该值（避免硬编码 15s 排空中被强杀）。
     */
    public function resolveShutdownWaitSeconds(): float
    {
        $maxDrain = 0.0;
        foreach ($this->registry()->allWorkers() as $processes) {
            foreach ($processes as $process) {
                if (!is_object($process) || !method_exists($process, 'getWaitTime')) {
                    continue;
                }
                $waitTime = (float) $process->getWaitTime();
                $maxExit = method_exists($process, 'getMaxWaitTimeOfExit')
                    ? (float) $process->getMaxWaitTimeOfExit()
                    : 30.0;
                $maxDrain = max($maxDrain, $waitTime + $maxExit);
            }
        }

        if ($maxDrain <= 0) {
            $maxDrain = 10.0 + 30.0;
        }

        return $maxDrain + (float) MainManager::SHUTDOWN_CLEANUP_MARGIN_SECONDS;
    }

    /**
     * 等待子进程退出；超时 SIGKILL，并用 wait/pcntl_waitpid(WNOHANG) 收尸，避免僵尸。
     */
    public function waitWorkersExitOrKill(float $timeoutSeconds): void
    {
        $timeoutSeconds = max(0.1, $timeoutSeconds);
        $deadline = microtime(true) + $timeoutSeconds;
        $alive = [];
        foreach ($this->registry()->allWorkers() as $processes) {
            foreach ($processes as $process) {
                try {
                    $pid = (int) $process->getPid();
                } catch (\Throwable $e) {
                    continue;
                }
                if ($pid > 0) {
                    $alive[$pid] = $process->getProcessName() . '#' . $process->getProcessWorkerId();
                }
            }
        }

        $pidList = $alive === [] ? '(none)' : implode(',', array_keys($alive));
        $this->manager->logInfo(
            "MainManager wait workers exit begin, timeout={$timeoutSeconds}s, alive_pids={$pidList}"
        );

        while ($alive !== [] && microtime(true) < $deadline) {
            while ($ret = \Swoole\Process::wait(false)) {
                if (is_array($ret) && isset($ret['pid'])) {
                    unset($alive[(int) $ret['pid']]);
                }
            }
            foreach ($alive as $pid => $name) {
                if (!@\posix_kill($pid, 0)) {
                    unset($alive[$pid]);
                }
            }
            if ($alive === []) {
                break;
            }
            usleep(50000);
        }

        if ($alive === []) {
            $this->manager->logInfo('MainManager all workers exited within shutdown budget');
            return;
        }

        $remain = implode(',', array_keys($alive));
        $this->manager->logError("MainManager enter force kill, remaining_pids={$remain}");
        foreach ($alive as $pid => $name) {
            $this->manager->logError("Master shutdown timeout, force kill worker pid={$pid} name={$name}");
            @\posix_kill($pid, SIGKILL);
            \Swoole\Process::wait(false);
            if (function_exists('pcntl_waitpid')) {
                $status = 0;
                @pcntl_waitpid($pid, $status, WNOHANG);
            }
        }
    }

    /**
     * 停止信号回调：统一走 MainManager 门面，便于单测 stub shutdownMainManager。
     *
     * @return \Closure
     */
    private function signalHandle()
    {
        return function ($signal) {
            switch ($signal) {
                case SIGINT:
                case SIGHUP:
                case SIGTERM:
                    $this->manager->shutdownMainManager('signal:' . $signal);
                    break;
                default:
                    break;
            }
        };
    }

    /**
     * 关机路径的异常不得阻断最终 process exit；上报失败也吞掉。
     */
    private function handleShutdownException(\Throwable $throwable): void
    {
        try {
            if (is_callable($this->manager->onHandleException)) {
                $this->manager->onHandleException->call($this->manager, $throwable);
            } else {
                $this->manager->logError('MainManager shutdown error: ' . $throwable->getMessage());
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Registry 只读（算等待预算、枚举存活 pid）。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
