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

namespace Swoolefy\Worker;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Config\WorkerConfLoader;
use Swoolefy\Worker\Process\ProcessIpc;
use Swoolefy\Worker\Process\ProcessRegistry;
use Swoolefy\Worker\Process\ProcessSupervisor;
use Swoolefy\Worker\Runtime\CliPipeServer;
use Swoolefy\Worker\Runtime\ProcessCommandHandler;
use Swoolefy\Worker\Runtime\SignalShutdown;
use Swoolefy\Worker\Runtime\StatusReporter;

/**
 * Daemon / Cron WorkerService 管理进程组合根与对外兼容门面。
 *
 * 本类不再持有进程表或 FIFO/信号处理实现，只负责：
 * 1. 单例与构造（协程 hook、默认 onHandleException）；
 * 2. start() 按固定顺序装配子系统（见方法注释，顺序不可乱）；
 * 3. 把历史 public API 转给 Config / Process / Runtime，应用侧 MainDaemonProcess 不用改。
 *
 * 子系统：
 * - WorkerConfLoader：conf / --group / confctl
 * - ProcessRegistry：唯一可变进程表
 * - ProcessSupervisor：fork / 动态扩缩容 / SIGCHLD
 * - ProcessIpc：pipe 读写
 * - CliPipeServer + ProcessCommandHandler：CLI 控制面
 * - SignalShutdown：信号与关机
 * - StatusReporter：WORKER_STATUS_FILE
 *
 * @see docs/MainManager-Split.md
 */
class MainManager
{
    use Traits\SingletonTrait, Traits\SystemTrait, Traits\MainProcessCommandTrait;

    /**
     * Master 等待 Worker 排空后的清理余量（秒），保证总上限不低于 Worker 最大排空时间。
     */
    public const SHUTDOWN_CLEANUP_MARGIN_SECONDS = 5;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var array
     */
    protected $defaultCoroutineSetting = [
        'enable_deadlock_check' => false
    ];

    /**
     * @var int
     */
    private $masterPid;

    /**
     * @var int
     */
    private $masterWorkerId = 0;

    /**
     * @var bool
     */
    private bool $isDaemon = false;

    /**
     * @var
     */
    private $startTime;

    /**
     * @var bool
     */
    private bool $isRunning = false;

    /**
     * @var bool
     */
    private bool $enablePipe = true;

    /**
     * @var \Closure
     */
    public $onStart;

    /**
     * @var \Closure
     */
    public $onPipeMsg;

    /**
     * @var \Closure
     */
    public $onProxyMsg;

    /**
     * @var \Closure
     */
    public $onCliMsg;

    /**
     * @var \Closure
     */
    public $onCreateDynamicProcess;

    /**
     * @var \Closure
     */
    public $onDestroyDynamicProcess;

    /**
     * @var \Closure
     */
    public $onReportStatus;

    /**
     * @var \Closure
     */
    public $onHandleException;

    /**
     * @var \Closure
     */
    public $onExit;

    /**
     * @var \Closure
     */
    public $onRegisterLogger;

    /**
     * @var \Closure
     */
    protected $closure;

    /**
     * 进程表。可变状态只放这里，禁止在本类再声明 processWorkers。
     */
    private ?ProcessRegistry $registry = null;

    /**
     * fork / 动态进程 / SIGCHLD。
     */
    private ?ProcessSupervisor $supervisor = null;

    /**
     * Master ↔ Worker 管道。
     */
    private ?ProcessIpc $ipc = null;

    /**
     * CLI / 关机共用的 start/stop/restart 命令。
     */
    private ?ProcessCommandHandler $commandHandler = null;

    /**
     * CLI FIFO。
     */
    private ?CliPipeServer $cliPipeServer = null;

    /**
     * 信号与 $isExit 唯一写入点。
     */
    private ?SignalShutdown $signalShutdown = null;

    /**
     * 状态 tick 与 CLI status 文本。
     */
    private ?StatusReporter $statusReporter = null;

    /**
     * 单 Master 最大子进程数系数：max = cpu_num * NUM_PEISHU。
     *
     * @var int
     */
    const NUM_PEISHU = 8;

    /**
     * 状态上报最小 tick（秒）。配置小于该值会被抬到 5s，降低 Master 写盘频率。
     *
     * @var int
     */
    const REPORT_STATUS_TICK_TIME = 5;

    /**
     * 管道消息里 Master 的逻辑名。Worker 用它判断「发给管理进程」。
     *
     * @var string
     */
    const MASTER_WORKER_NAME = 'master_worker';

    /**
     * Worker 管道消息 action：请求 Master 动态扩容。
     *
     * @var string
     */
    const CREATE_DYNAMIC_PROCESS_WORKER = 'create_dynamic_process_worker';

    /**
     * Worker 管道消息 action：请求 Master 动态缩容。
     *
     * @var string
     */
    const DESTROY_DYNAMIC_PROCESS_WORKER = 'destroy_dynamic_process_worker';

    /**
     * Worker 管道消息 action：请求 Master reboot 指定 pid。
     *
     * @var string
     */
    const REBOOT_PROCESS_WORKER = 'reboot_process_worker';

    /**
     * @param array $config 含 coroutine_setting / report_status_tick_time 等
     * @param mixed ...$args
     */
    public function __construct(array $config = [], ...$args)
    {
        $this->config = $config;
        $this->setCoroutineSetting(array_merge($this->defaultCoroutineSetting, $config['coroutine_setting'] ?? []));
        $this->onHandleException = function (\Throwable $exception) {
        };
        $this->bootCollaborators();
    }

    /**
     * 登记进程定义（尚未 fork）。门面 → ProcessSupervisor::addProcess。
     *
     * @param string $processName
     * @param string $processClass
     * @param int $processWorkerNum
     * @param bool $async
     * @param array $args
     * @param mixed $extendData
     * @param bool $enableCoroutine
     */
    public function addProcess(
        string $processName,
        string $processClass,
        int    $processWorkerNum = 1,
        bool   $async = true,
        array  $args = [],
        ?array $extendData = null,
        bool   $enableCoroutine = true
    ) {
        $this->getSupervisor()->addProcess(
            $processName,
            $processClass,
            $processWorkerNum,
            $async,
            $args,
            $extendData,
            $enableCoroutine
        );
    }

    /**
     * 启动阶段校验 wait_time：配置非法则失败，避免 Master 使用过短隐式等待。
     *
     * @param array $args
     * @param string $processName
     */
    public function validateWorkerWaitTime(array $args, string $processName): void
    {
        if (!array_key_exists('wait_time', $args)) {
            return;
        }
        if (!is_numeric($args['wait_time']) || (float) $args['wait_time'] <= 0) {
            throw new WorkerException(
                "Process={$processName} args.wait_time must be a positive number"
            );
        }
    }

    /**
     * 写入 Registry 进程配置。兼容旧 Trait / 子类调用。
     *
     * @param string $processName
     * @param string $processClass
     * @param int $processWorkerNum
     * @param array $args
     * @param array $extendData
     * @return void
     */
    protected function setProcessLists(
        string $processName,
        string $processClass,
        int $processWorkerNum,
        array $args,
        array $extendData
    ) {
        $this->getSupervisor()->setProcessLists($processName, $processClass, $processWorkerNum, $args, $extendData);
    }

    /**
     * 按 conf 数组批量 addProcess。AbstractMainProcess::init() 调用。
     *
     * @param array $conf
     */
    public function loadConf(array $conf)
    {
        return $this->getSupervisor()->loadConf($conf);
    }

    /**
     * 摊平 conf 字段到 args。门面 → Supervisor。
     *
     * @param array $args
     * @param array $config
     */
    protected function parseArgs(array &$args, array $config)
    {
        $this->getSupervisor()->parseArgs($args, $config);
    }

    /**
     * 启动编排。顺序固定：
     * errorHandler → setMasterPid → status timer → initStart(fork)
     * → setRunning → CLI FIFO → SIGCHLD → stop/reload 信号 → shutdown function
     * → 自定义信号 → bind 全部 pipe → 记 startTime。
     *
     * 先装 timer 再 fork：timer 回调禁止协程；setRunning 在 FIFO 之前，
     * 避免控制面在 Master 未就绪时 writeByProcessName。
     *
     * @return mixed
     */
    public function start()
    {
        try {
            if (!empty($this->getRegistry()->allLists())) {
                $this->installErrorHandler();
                $this->setMasterPid(posix_getpid());
                $this->getStatusReporter()->install();
                $this->getSupervisor()->initStart();
                $this->setRunning();
                $this->getCliPipeServer()->install();
                $this->getSupervisor()->installSigchldSignal();
                $this->getSignalShutdown()->installStopSignal();
                $this->getSignalShutdown()->installReloadSignal();
                $this->getSignalShutdown()->installRegisterShutdownFunction();
                $this->getSignalShutdown()->installCustomSignals();
                $this->getIpc()->swooleEventAdd();
                $this->setStartTime();
            }
            $masterPid = $this->getMasterPid();
            $this->saveMasterPidToFile($masterPid);
            $this->saveStatusToFile();
            if ($masterPid && is_callable($this->onStart)) {
                $this->onStart && $this->onStart->call($this, $masterPid);
            }
            return $masterPid;
        } catch (\Throwable $throwable) {
            $this->handleWorkerException($throwable);
        }
    }

    /**
     * 设置协程 hook。空 hook_flags 时回退 Swfy conf 或 SWOOLE_HOOK_ALL。
     *
     * @param array $setting
     * @return void
     */
    public function setCoroutineSetting(array $setting)
    {
        $setting['hook_flags'] = $this->getHookFlags($this->config['coroutine_setting']['hook_flags'] ?? '');
        $setting = array_merge(\Swoole\Coroutine::getOptions() ?? [], $setting);
        !empty($setting) && \Swoole\Coroutine::set($setting);
    }

    /**
     * @param string $name
     * @return void
     */
    public function setCliMasterName(string $name = '')
    {
        $this->closure = function () use ($name) {
            if ($name) {
                cli_set_process_title($name);
            }
        };
    }

    /**
     * 子进程退出后检查是否还可让 Master 继续活着。WorkerService 下恒为 true。
     *
     * @return bool
     */
    public function checkMasterToExit()
    {
        if (isWorkerService()) {
            return true;
        }
    }

    /**
     * 监听 Worker pipe。reboot/动态 fork 后只传新进程，避免重复 Event::add。
     *
     * @param AbstractBaseWorker|null $currentProcess
     * @return mixed
     */
    public function swooleEventAdd(?AbstractBaseWorker $currentProcess = null)
    {
        $this->getIpc()->swooleEventAdd($currentProcess);
    }

    /**
     * 写 Master pid 到 WORKER_PID_FILE（路径已按 --group 隔离）。
     *
     * @param int $workerMasterPid
     * @return void
     */
    public function saveMasterPidToFile(int $workerMasterPid)
    {
        @file_put_contents(WORKER_PID_FILE, $workerMasterPid);
    }

    /**
     * 立刻写 WORKER_STATUS_FILE。
     *
     * @param array $status
     * @return void
     */
    public function saveStatusToFile(array $status = [])
    {
        $this->getStatusReporter()->saveStatusToFile($status);
    }

    /**
     * 动态扩容。门面 → Supervisor；关机中会拒绝。
     *
     * @param string $processName
     * @param int $processNum
     * @return mixed
     * @throws WorkerException
     */
    public function createDynamicProcess(string $processName, int $processNum = 2)
    {
        return $this->getSupervisor()->createDynamicProcess($processName, $processNum);
    }

    /**
     * 可被单测/子类 override 的 fork 钩子。默认转 Supervisor::doFork。
     * 动态扩容必须显式传 PROCESS_DYNAMIC_TYPE。
     *
     * @param $processClass
     * @param $processName
     * @param $workerId
     * @param $args
     * @param $extendData
     * @param int $processType PROCESS_STATIC_TYPE|PROCESS_DYNAMIC_TYPE
     * @return void
     */
    protected function forkNewProcess(
        $processClass,
        $processName,
        $workerId,
        $args = [],
        $extendData = [],
        int $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE
    ) {
        $this->getSupervisor()->doFork(
            $processClass,
            $processName,
            $workerId,
            $args,
            $extendData,
            $processType
        );
    }

    /**
     * Supervisor / CommandHandler 的 fork 入口。
     * 故意走本方法再进 protected forkNewProcess，这样匿名子类 override forkNewProcess 仍然生效。
     *
     * @param mixed $processClass
     * @param mixed $processName
     * @param mixed $workerId
     * @param mixed $args
     * @param mixed $extendData
     */
    public function invokeForkNewProcess(
        $processClass,
        $processName,
        $workerId,
        $args = [],
        $extendData = [],
        int $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE
    ): void {
        $this->forkNewProcess($processClass, $processName, $workerId, $args, $extendData, $processType);
    }

    /**
     * 缩容：只向 dynamic 进程发退出信号；计数延后到 SIGCHLD reap，重复请求幂等。
     *
     * @param string $processName
     * @param int $processNum
     * @return void
     * @throws WorkerException
     */
    public function destroyDynamicProcess(string $processName, int $processNum = -1)
    {
        $this->getSupervisor()->destroyDynamicProcess($processName, $processNum);
    }

    /**
     * 按存活实例重算动态进程数。门面 → Supervisor。
     *
     * @param string $processName
     * @return int
     * @throws WorkerException
     */
    public function storageDynamicProcessNum(string $processName)
    {
        return $this->getSupervisor()->storageDynamicProcessNum($processName);
    }

    /**
     * @return int
     */
    public function getMasterPid()
    {
        return $this->masterPid;
    }

    /**
     * 是否 Master 逻辑名（master_worker）。禁止 Master 给自己写管道。
     *
     * @param string $processName
     * @return bool
     */
    public function isMaster(string $processName)
    {
        if ($processName == $this->getMasterWorkerName()) {
            return true;
        }
        return false;
    }

    /**
     * 采集并（可选）落盘进程状态。门面 → StatusReporter。
     *
     * @param int $runningStatus
     * @return array
     */
    public function getProcessStatus(int $runningStatus = 1)
    {
        return $this->getStatusReporter()->getProcessStatus($runningStatus);
    }

    /**
     * 按名取 worker。workerId>=0 且不存在则抛错；workerId<0 返回该名下全部（可能为 null）。
     *
     * @param string $processName
     * @param int $processWorkerId
     * @return mixed|null
     */
    public function getProcessByName(string $processName, int $processWorkerId = 0)
    {
        $worker = $this->getRegistry()->getWorker($processName, $processWorkerId);
        if ($worker !== null) {
            return $worker;
        }
        if ($processWorkerId < 0) {
            return $this->getRegistry()->getWorkersByName($processName);
        }
        throw new WorkerException("Missing and not found process_name={$processName}, worker_id={$processWorkerId}");
    }

    /**
     * getProcessByPid
     * @param int $pid
     * @return mixed
     */
    public function getProcessByPid(int $pid)
    {
        return $this->getRegistry()->findByPid($pid);
    }

    /**
     * @param string $processName
     * @param int $processWorkerId
     * @return mixed
     */
    public function getPidByName(string $processName, int $processWorkerId)
    {
        $process = $this->getProcessByName($processName, $processWorkerId);
        return is_object($process) ? $process->getPid() : null;
    }

    /**
     * getProcessWorkerId
     * @return int
     */
    public function getMasterWorkerId(): int
    {
        return $this->masterWorkerId;
    }

    /**
     * getMasterWorkerName
     * @return string
     */
    public function getMasterWorkerName(): string
    {
        return MainManager::MASTER_WORKER_NAME;
    }

    /**
     * Master 是否已进入 shutdown（动态扩容必须检查）。
     *
     * @return bool
     */
    public function isMasterExiting(): bool
    {
        return $this->getSignalShutdown()->isExiting();
    }

    /**
     * Master 向指定 Worker 写管道。门面 → ProcessIpc。
     *
     * @param string $processName
     * @param mixed $data
     * @param int $processWorkerId
     * @return bool
     */
    public function writeByProcessName(string $processName, $data, int $processWorkerId = 0)
    {
        return $this->getIpc()->writeByProcessName($processName, $data, $processWorkerId);
    }

    /**
     * master proxy worker message
     * @param mixed $data
     * @param string $fromProcessName
     * @param int $fromProcessWorkerId
     * @param string $toProcessName
     * @param int $toProcessWorkerId
     * @return bool
     */
    public function writeByMasterProxy(
        $data,
        string $fromProcessName,
        int $fromProcessWorkerId,
        string $toProcessName,
        int $toProcessWorkerId
    ) {
        return $this->getIpc()->writeByMasterProxy(
            $data,
            $fromProcessName,
            $fromProcessWorkerId,
            $toProcessName,
            $toProcessWorkerId
        );
    }

    /**
     * broadcast message to all worker
     * @param string $processName
     * @param mixed $data
     * @return void
     */
    public function broadcastProcessWorker(string $processName, $data = '')
    {
        $this->getIpc()->broadcastProcessWorker($processName, $data);
    }

    /**
     * 注册业务自定义信号。SIGTERM/USR1/USR2/CHLD 不可覆盖。
     *
     * @param int $signal
     * @param callable $function
     * @return void
     */
    public function addSignal(int $signal, callable $function)
    {
        $this->getSignalShutdown()->addSignal($signal, $function);
    }

    /**
     * 是否允许 CLI FIFO。false 时 install() 直接返回，用于纯脚本/测试。
     *
     * @param bool $enablePipe
     * @return void
     */
    public function enableCliPipe(bool $enablePipe = true)
    {
        $this->enablePipe = $enablePipe;
    }

    /**
     * 是否启用 CLI FIFO。start() 里 CliPipeServer::install 读取。
     */
    public function isCliPipeEnabled(): bool
    {
        return $this->enablePipe;
    }

    /**
     * 当前实例 CLI→Worker FIFO 路径（随 WORKER_SERVICE_NAME / --group 隔离）。
     *
     * @return string
     */
    public function getCliToWorkerPipeFile()
    {
        return CLI_TO_WORKER_PIPE;
    }

    /**
     * getCliEnvParam
     * @param string $name
     * @return array|false|string|null
     */
    public function getCliEnvParam(string $name)
    {
        $value = @getenv($name);
        if ($value !== false) {
            return $value;
        }
        return null;
    }

    /**
     * Master 是否已 start。writeByProcessName 在 false 时拒绝，防止启动竞态。
     *
     * @return bool
     */
    public function isRunning()
    {
        if (isset($this->isRunning) && $this->isRunning === true) {
            return true;
        }
        return false;
    }

    /**
     * 实时加载配置并合并 confctl。门面 → WorkerConfLoader，CtlApi/AbstractMainProcess 不用改 import。
     *
     * @param string $confPath
     * @return array
     */
    public static function loadWorkerConf(string $confPath)
    {
        return WorkerConfLoader::loadWorkerConf($confPath);
    }

    /**
     * include 当前 worker conf（已按 --group 过滤）。不改 confctl。
     *
     * @return array
     */
    public static function includeWorkerConf()
    {
        return WorkerConfLoader::includeWorkerConf();
    }

    /**
     * 是否为分组形式的 worker 配置：顶层键为分组名，值为进程配置列表。
     *
     * @param array<int|string, mixed> $conf
     */
    public static function isGroupedWorkerConf(array $conf): bool
    {
        return WorkerConfLoader::isGroupedWorkerConf($conf);
    }

    /**
     * 按 CLI --group= 解析分组配置。
     *
     * @param array<string, array<int, array<string, mixed>>> $groupedConf
     *
     * @return list<array<string, mixed>>
     */
    public static function resolveGroupedWorkerConf(array $groupedConf): array
    {
        return WorkerConfLoader::resolveGroupedWorkerConf($groupedConf);
    }

    /**
     * include + confctl 合并。门面保留给历史 protected 调用。
     *
     * @param string $confPath
     * @return array
     */
    protected static function defaultLoadWorkerConf(string $confPath)
    {
        return WorkerConfLoader::defaultLoadWorkerConf($confPath);
    }

    /**
     * 重复 process_name 检测。门面 → Loader（会真正 throw）。
     *
     * @param array $conf
     * @return void
     */
    public static function findDuplicateProcessName(array &$conf)
    {
        WorkerConfLoader::findDuplicateProcessName($conf);
    }

    /**
     * 进程表。可变状态只放这里。get* 均懒加载 bootCollaborators（单测可跳过父构造）。
     */
    public function getRegistry(): ProcessRegistry
    {
        $this->bootCollaborators();
        return $this->registry;
    }

    public function getSupervisor(): ProcessSupervisor
    {
        $this->bootCollaborators();
        return $this->supervisor;
    }

    public function getIpc(): ProcessIpc
    {
        $this->bootCollaborators();
        return $this->ipc;
    }

    public function getCommandHandler(): ProcessCommandHandler
    {
        $this->bootCollaborators();
        return $this->commandHandler;
    }

    public function getCliPipeServer(): CliPipeServer
    {
        $this->bootCollaborators();
        return $this->cliPipeServer;
    }

    public function getSignalShutdown(): SignalShutdown
    {
        $this->bootCollaborators();
        return $this->signalShutdown;
    }

    public function getStatusReporter(): StatusReporter
    {
        $this->bootCollaborators();
        return $this->statusReporter;
    }

    /**
     * 构造传入的 worker 配置（report_status_tick_time 等）。
     *
     * @return array<string, mixed>
     */
    public function getWorkerConfig(): array
    {
        return $this->config ?? [];
    }

    /**
     * @return mixed
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * 子系统日志入口，保证 stub 覆盖 fmtWriteInfo 仍然生效。
     */
    public function logInfo($msg): void
    {
        $this->fmtWriteInfo($msg);
    }

    /**
     * 子系统错误日志入口。
     */
    public function logError($msg): void
    {
        $this->fmtWriteError($msg);
    }

    /**
     * 把异常交给应用设置的 onHandleException（call 的 $this 仍是 MainManager）。
     */
    public function handleWorkerException(\Throwable $throwable): void
    {
        if (is_callable($this->onHandleException)) {
            $this->onHandleException->call($this, $throwable);
        }
    }

    /**
     * 是否在 Worker Master 进程（相对业务子进程）。关机清理只在 Master 执行。
     */
    public function isInMasterProcessEnv(): bool
    {
        return $this->inMasterProcessEnv();
    }

    /**
     * reboot 指定 pid。门面 → Supervisor。
     */
    public function rebootWorker(int $pid): void
    {
        $this->getSupervisor()->rebootWorker($pid);
    }

    /**
     * MainManager 唯一退出入口（Signal 与 CLI FIFO stop 共用）。
     */
    public function shutdownMainManager(string $source): void
    {
        $this->getSignalShutdown()->shutdown($source);
    }

    /**
     * Master 优雅退出等待上限：取各 Worker 的 wait_time + maxWaitTimeOfExit 最大值，再加清理余量。
     */
    protected function resolveShutdownWaitSeconds(): float
    {
        return $this->getSignalShutdown()->resolveShutdownWaitSeconds();
    }

    /**
     * 通知退出后等待子进程结束；超时则 SIGKILL 并记录未正常退出的 PID。
     */
    protected function waitWorkersExitOrKill(float $timeoutSeconds): void
    {
        $this->getSignalShutdown()->waitWorkersExitOrKill($timeoutSeconds);
    }

    /**
     * 标记 Master 已完成 initStart，允许 writeByProcessName。
     *
     * @return bool
     */
    protected function setRunning()
    {
        $this->isRunning = true;
    }

    /**
     * 最大子进程数。兼容 Trait 旧调用。
     *
     * @return float|int
     */
    private function getMaxProcessNum()
    {
        return $this->getSupervisor()->maxProcessNum();
    }

    /**
     * 记录 Master pid、设进程标题、定义 WORKER_MASTER_PID。
     *
     * @return void
     */
    private function setMasterPid(int $masterId)
    {
        $this->masterPid = $masterId;
        if (SystemEnv::isDaemonService()) {
            cli_set_process_title(BaseServer::getAppPrefix()."-php-daemon-master:" . WORKER_START_SCRIPT_FILE);
        }else if (SystemEnv::isCronService()) {
            cli_set_process_title(BaseServer::getAppPrefix()."-php-cron-master:" . WORKER_START_SCRIPT_FILE);
        }else if (SystemEnv::isScriptService()) {
            cli_set_process_title(BaseServer::getAppPrefix()."-php-script-master:" . WORKER_START_SCRIPT_FILE);
        }

        defined('WORKER_MASTER_PID') OR define('WORKER_MASTER_PID', $this->masterPid);
    }

    /**
     * setStartTime
     * @return void
     */
    private function setStartTime()
    {
        $this->startTime = date('Y-m-d H:i:s', strtotime('now'));
    }

    /**
     * 懒装配全部协作者。单测跳过 __construct 时第一次 getRegistry() 仍会走到这里。
     * 顺序：Registry 必须最先创建，其余组件只拿 MainManager 再回查 Registry。
     */
    private function bootCollaborators(): void
    {
        if ($this->registry !== null) {
            return;
        }
        $this->registry = new ProcessRegistry();
        $this->ipc = new ProcessIpc($this);
        $this->supervisor = new ProcessSupervisor($this);
        $this->commandHandler = new ProcessCommandHandler($this);
        $this->cliPipeServer = new CliPipeServer($this);
        $this->signalShutdown = new SignalShutdown($this);
        $this->statusReporter = new StatusReporter($this);
    }
}
