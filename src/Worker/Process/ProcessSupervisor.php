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

namespace Swoolefy\Worker\Process;

use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\AbstractWorkerProcess;
use Swoolefy\Worker\MainManager;

/**
 * 进程生命周期：fork / 静态启动 / 动态扩缩容 / reboot / SIGCHLD 回收。
 *
 * 技术点：
 * - 进程表只经 ProcessRegistry 改写，禁止再持有一份 processWorkers；
 * - 真正 fork 走 MainManager::invokeForkNewProcess → 可被覆盖的 forkNewProcess，
 *   单测才能 stub 而不启动真实 Swoole\Process；
 * - 动态进程必须显式 PROCESS_DYNAMIC_TYPE，缩容只停 dynamic，计数延后到 SIGCHLD reap。
 */
final class ProcessSupervisor
{
    public function __construct(private MainManager $manager)
    {
    }

    /**
     * 登记一个进程定义到 Registry（尚未 fork）。
     *
     * 技术点：enableCoroutine / async 入参即使传 false 也会被强制为 true（历史行为，协程 Worker 模型）；
     * max_process_num 上限 = CPU 核数 * NUM_PEISHU(8)；wait_time 非法则启动失败。
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed>|null $extendData
     */
    public function addProcess(
        string $processName,
        string $processClass,
        int $processWorkerNum = 1,
        bool $async = true,
        array $args = [],
        ?array $extendData = null,
        bool $enableCoroutine = true
    ): void {
        $registry = $this->registry();
        if ($registry->hasList($processName)) {
            throw new WorkerException("【Error】You can not add the same process={$processName}");
        }
        if (!$enableCoroutine) {
            $enableCoroutine = true;
        }
        if (!$async) {
            $async = true;
        }

        $maxProcessNum = $this->maxProcessNum();

        if (isset($args['max_process_num']) && $args['max_process_num'] > $maxProcessNum) {
            $args['max_process_num'] = $maxProcessNum;
        } else {
            $args['max_process_num'] = $maxProcessNum;
        }

        if ($processWorkerNum > $maxProcessNum) {
            $this->manager->logInfo("Process Name={$processName}, params of process_worker_num more then max_process_num={$maxProcessNum}");
            $processWorkerNum = $maxProcessNum;
        }
        $this->manager->validateWorkerWaitTime($args, $processName);
        $this->setProcessLists($processName, $processClass, $processWorkerNum, $args, $extendData ?? []);
    }

    /**
     * 把 worker_daemon_conf 扁平列表登记进 Registry。
     *
     * Cron 服务强制 worker_num=1（定时任务不并行多 worker 抢同一调度）。
     *
     * @param array<int, array<string, mixed>> $conf
     */
    public function loadConf(array $conf): MainManager
    {
        foreach ($conf as $config) {
            $async = true;
            $enableCoroutine = true;
            $processName = $config['process_name'];
            $processClass = $config['handler'];
            $processWorkerNum = $config['worker_num'] ?? 1;
            if (SystemEnv::isCronService()) {
                $processWorkerNum = 1;
            }
            $args = $config['args'] ?? [];
            $this->parseArgs($args, $config);
            $extendData = $config['extend_data'] ?? [];
            $this->addProcess($processName, $processClass, $processWorkerNum, $async, $args, $extendData, $enableCoroutine);
        }

        return $this->manager;
    }

    /**
     * 把 conf 顶层字段（max_handle / life_time / 描述等）摊进 args，供 Worker 运行时读取。
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $config
     */
    public function parseArgs(array &$args, array $config): void
    {
        $args['max_handle'] = $config['max_handle'] ?? 10000;
        $args['life_time'] = $config['life_time'] ?? 3600;
        $args['limit_run_coroutine_num'] = $config['limit_run_coroutine_num'] ?? null;
        $args['description'] = $config['description'] ?? '';
    }

    /**
     * 写入 Registry processLists。async / enable_coroutine 固定 true。
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $extendData
     */
    public function setProcessLists(
        string $processName,
        string $processClass,
        int $processWorkerNum,
        array $args,
        array $extendData
    ): void {
        $this->registry()->setList($processName, [
            'process_name' => $processName,
            'process_class' => $processClass,
            'process_worker_num' => $processWorkerNum,
            'async' => true,
            'args' => $args,
            'extend_data' => $extendData,
            'enable_coroutine' => true,
        ]);
    }

    /**
     * 启动阶段：先 new 全部 Worker 对象再统一 start()。
     *
     * 分两轮是为了避免「边 fork 边 start」时后续 new 受到已运行子进程异步 IO 影响。
     * 每步 usleep(50ms) 降低瞬间 fork 风暴。单个 new 失败只记日志，不中断其它进程。
     */
    public function initStart(): void
    {
        $registry = $this->registry();
        foreach ($registry->allLists() as $key => $list) {
            $processWorkerNum = $list['process_worker_num'] ?? 1;
            for ($workerId = 0; $workerId < $processWorkerNum; $workerId++) {
                try {
                    $processName = $list['process_name'];
                    $processClass = $list['process_class'];
                    $async = $list['async'] ?? true;
                    $args = $list['args'] ?? [];
                    $extendData = $list['extend_data'] ?? null;
                    $enableCoroutine = $list['enable_coroutine'] ?? true;
                    /** @var AbstractWorkerProcess $process */
                    $process = new $processClass(
                        $processName,
                        $async,
                        $args,
                        $extendData,
                        $enableCoroutine
                    );
                    $process->setProcessWorkerId($workerId);
                    $process->setMasterPid($this->manager->getMasterPid());
                    $process->setStartTime();
                    if ($registry->getWorker($processName, $workerId) === null) {
                        $registry->setWorker($processName, $workerId, $process);
                    }
                    usleep(50000);
                } catch (\Throwable $throwable) {
                    $this->manager->handleWorkerException($throwable);
                }
            }
        }

        foreach ($registry->allWorkers() as $workers) {
            foreach ($workers as $process) {
                $process->start();
                usleep(50000);
            }
        }
    }

    /**
     * 注册 SIGCHLD：子进程退出后非阻塞 wait，走 rebootOrExitHandle。
     */
    public function installSigchldSignal(): void
    {
        \Swoole\Process::signal(SIGCHLD, function ($signo) {
            $this->rebootOrExitHandle();
        });
    }

    /**
     * 回收子进程（非阻塞 Process::wait(false)）。
     *
     * 退出码 0 / SIGTERM / SIGKILL：视为正常退出，从表删除；
     * 动态进程确认退出后再减计数，避免扩缩容窗口把「正在停」当成「已停」再扩出来。
     * SIGUSR1 及其它：视为 reboot，拉起同 workerId 的新进程并保留 process_type。
     * 未知 pid 幂等跳过（可能已被 waitWorkersExitOrKill 收过）。
     */
    public function rebootOrExitHandle(): void
    {
        while ($ret = \Swoole\Process::wait(false)) {
            if (!is_array($ret) || !isset($ret['pid'])) {
                $this->manager->logError("Swoole\Process::wait error");
                return;
            }
            $pid = $ret['pid'];
            $code = $ret['code'];

            try {
                switch ($code) {
                    case 0:
                    case SIGTERM:
                    case SIGKILL:
                        /** @var AbstractBaseWorker $process */
                        $process = $this->manager->getProcessByPid($pid);
                        if (!is_object($process)) {
                            continue 2;
                        }
                        $processName = $process->getProcessName();
                        $processWorkerId = $process->getProcessWorkerId();
                        $isDynamicProcess = $process->isDynamicProcess();
                        $this->registry()->removeWorker($processName, $processWorkerId);
                        if ($isDynamicProcess) {
                            $this->registry()->unmarkStoppingDynamic($pid);
                            $this->storageDynamicProcessNum($processName);
                            $list = $this->registry()->getList($processName);
                            $list['dynamic_process_destroying'] = $this->registry()->hasStoppingDynamicProcess($processName);
                            $this->registry()->setList($processName, $list);
                        }
                        @\Swoole\Event::del($process->getSwooleProcess()->pipe);
                        $this->manager->checkMasterToExit();
                        break;

                    case SIGUSR1:
                    default:
                        $this->manager->rebootWorker($pid);
                        break;
                }
            } catch (\Throwable $throwable) {
                $this->manager->handleWorkerException($throwable);
            }
        }
    }

    /**
     * 按原 workerId / processType / rebootCount+1 拉起替换进程，并重新 Event::add(pipe)。
     * fork 失败必须 removeWorker，避免表里有对象、内核里没进程。
     */
    public function rebootWorker(int $pid): void
    {
        /** @var AbstractBaseWorker $process */
        $process = $this->manager->getProcessByPid($pid);
        if (!is_object($process)) {
            return;
        }
        $processName = $process->getProcessName();
        $processType = $process->getProcessType();
        $processWorkerId = $process->getProcessWorkerId();
        $processRebootCount = $process->getRebootCount() + 1;
        $list = $this->registry()->getList($processName);
        @\Swoole\Event::del($process->getSwooleProcess()->pipe);
        $this->registry()->removeWorker($processName, $processWorkerId);
        try {
            $processName = $list['process_name'];
            $processClass = $list['process_class'];
            $async = $list['async'] ?? true;
            $args = $list['args'] ?? [];
            $extendData = $list['extend_data'] ?? null;
            $enableCoroutine = $list['enable_coroutine'] ?? true;
            /** @var AbstractBaseWorker $newProcess */
            $newProcess = new $processClass(
                $processName,
                $async,
                $args,
                $extendData,
                $enableCoroutine
            );
            $newProcess->setProcessWorkerId($processWorkerId);
            $newProcess->setMasterPid($this->manager->getMasterPid());
            $newProcess->setProcessType($processType);
            $newProcess->setRebootCount($processRebootCount);
            $newProcess->setStartTime();
            $this->registry()->setWorker($processName, $processWorkerId, $newProcess);
            $newProcess->start();
            $this->manager->swooleEventAdd($newProcess);
        } catch (\Throwable $throwable) {
            $this->registry()->removeWorker($processName, $processWorkerId);
            $this->manager->handleWorkerException($throwable);
        }
    }

    /**
     * 动态扩容。Master 正在退出或该进程名正在 destroying 时拒绝。
     *
     * 技术点：fork 第 6 参必须传 PROCESS_DYNAMIC_TYPE；
     * 总数不超过 args.max_process_num；扩容后再 storageDynamicProcessNum 按存活实例重算。
     *
     * @return mixed
     */
    public function createDynamicProcess(string $processName, int $processNum = 2)
    {
        if ($this->manager->isMasterExiting()) {
            $this->manager->logInfo("Master process is exiting now，forbidden to create dynamic process");
            return false;
        }

        $registry = $this->registry();
        $this->storageDynamicProcessNum($processName);
        $list = $registry->getList($processName);
        if ($list['dynamic_process_destroying'] ?? false) {
            $msg = "【Warning】 Process name={$processName} is exiting now，forbidden to create dynamic process, please try again after moment";
            throw new WorkerException($msg);
        }

        if ($processNum <= 0) {
            $processNum = 1;
        }

        $processWorkerNum = $list['process_worker_num'];
        $processName = $list['process_name'];
        $processClass = $list['process_class'];
        if (isset($list['dynamic_process_worker_num']) && $list['dynamic_process_worker_num'] > 0) {
            $totalProcessNum = $processWorkerNum + $list['dynamic_process_worker_num'] + $processNum;
        } else {
            $totalProcessNum = $processWorkerNum + $processNum;
            $list['dynamic_process_worker_num'] = 0;
            $registry->setList($processName, $list);
        }

        if ($totalProcessNum > $list['args']['max_process_num']) {
            $totalProcessNum = $list['args']['max_process_num'];
        }
        $args = $list['args'];
        $extendData = $list['extend_data'];
        $runningProcessWorkerNum = $processWorkerNum + $list['dynamic_process_worker_num'];

        if ($runningProcessWorkerNum >= $totalProcessNum) {
            $msg = "【Warning】 Children process num={$totalProcessNum}, achieve max_process_num，forbidden to create process";
            throw new WorkerException($msg);
        }

        for ($workerId = $runningProcessWorkerNum; $workerId < $totalProcessNum; $workerId++) {
            $this->manager->invokeForkNewProcess(
                $processClass,
                $processName,
                $workerId,
                $args,
                $extendData,
                AbstractBaseWorker::PROCESS_DYNAMIC_TYPE
            );
        }
        $this->storageDynamicProcessNum($processName);
    }

    /**
     * 真正 fork：new Worker → 登记 Registry → start → 监听 pipe。
     *
     * processType 由调用方决定，默认 PROCESS_STATIC_TYPE，避免历史静态入口被改成 dynamic。
     * 失败路径：removeWorker + unset 对象，防止半初始化实例留在表里。
     *
     * @param mixed $processClass
     * @param mixed $processName
     * @param mixed $workerId
     * @param mixed $args
     * @param mixed $extendData
     */
    public function doFork(
        $processClass,
        $processName,
        $workerId,
        $args = [],
        $extendData = [],
        int $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE
    ): void {
        try {
            /** @var AbstractBaseWorker $newProcess */
            $newProcess = new $processClass(
                $processName,
                true,
                $args,
                $extendData,
                true
            );
            $newProcess->setProcessWorkerId($workerId);
            $newProcess->setMasterPid($this->manager->getMasterPid());
            $newProcess->setProcessType($processType);
            $newProcess->setStartTime();
            $this->registry()->setWorker($processName, $workerId, $newProcess);
            $newProcess->start();
            $this->manager->swooleEventAdd($newProcess);
            $this->manager->logInfo("Process name={$processName},worker_id={$workerId} create successful");
        } catch (\Throwable $throwable) {
            $this->registry()->removeWorker($processName, $workerId);
            unset($newProcess);
            $this->manager->handleWorkerException($throwable);
        }
    }

    /**
     * 缩容：只向 isDynamicProcess() 的 worker 发 EXIT；static 跳过。
     *
     * `$processNum >= 0` 时最多停这么多个；-1 表示停掉该名下全部动态进程。
     * 发信号时只 markStoppingDynamic，不减 dynamic_process_worker_num；
     * 同一 pid 已在 stopping 集合则幂等跳过。写 pipe 失败要 unmark，避免永久卡在 destroying。
     */
    public function destroyDynamicProcess(string $processName, int $processNum = -1): void
    {
        $processWorkers = $this->manager->getProcessByName($processName, -1);
        $stoppingCount = 0;
        foreach ($processWorkers as $workerId => $process) {
            if (!$process->isDynamicProcess() || ($processNum >= 0 && $stoppingCount >= $processNum)) {
                continue;
            }

            $pid = (int) $process->getPid();
            if ($pid <= 0 || $this->registry()->isStoppingDynamicPid($pid)) {
                continue;
            }

            $this->registry()->markStoppingDynamic($pid, $processName);
            $list = $this->registry()->getList($processName);
            $list['dynamic_process_destroying'] = true;
            $this->registry()->setList($processName, $list);
            try {
                $this->manager->writeByProcessName($processName, AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $workerId);
                ++$stoppingCount;
                $this->manager->logInfo("Dynamic process={$processName},worker_id={$workerId} stopping");
            } catch (\Throwable $e) {
                $this->registry()->unmarkStoppingDynamic($pid);
                $this->manager->logError("DestroyDynamicProcess error message=" . $e->getMessage());
            }
        }
        $list = $this->registry()->getList($processName);
        $list['dynamic_process_destroying'] = $this->registry()->hasStoppingDynamicProcess($processName);
        $this->registry()->setList($processName, $list);
    }

    /**
     * 按当前存活实例重算动态进程数（只计 isDynamicProcess()）。
     * reap 之后调用，保证计数不为负、也不提前减。
     */
    public function storageDynamicProcessNum(string $processName): int
    {
        $dynamicProcessNum = 0;
        $processWorkers = $this->manager->getProcessByName($processName, -1) ?? [];
        foreach ($processWorkers as $process) {
            if ($process->isDynamicProcess()) {
                ++$dynamicProcessNum;
            }
        }

        $list = $this->registry()->getList($processName);
        $list['dynamic_process_worker_num'] = $dynamicProcessNum;
        $this->registry()->setList($processName, $list);

        return $dynamicProcessNum;
    }

    /**
     * 单 Master 允许的最大子进程数：CPU 核数 × 8。
     *
     * @return float|int
     */
    public function maxProcessNum()
    {
        return (swoole_cpu_num()) * (MainManager::NUM_PEISHU);
    }

    /**
     * Registry 访问入口，避免 Supervisor 再缓存一份表引用。
     */
    private function registry(): ProcessRegistry
    {
        return $this->manager->getRegistry();
    }
}
