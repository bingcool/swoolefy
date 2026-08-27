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

namespace Swoolefy\Worker\Cron;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\AbstractWorkerProcess;
use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;

/**
 * Cron Worker 基类：fork / url / DB cron 的唯一调度入口是 CronManager。
 *
 * 管线：task_list（静态数组或 DB Closure）→ fetcher → ConfigDiff → Runtime
 * → one-shot nextRunAt Timer → Guard / 时间窗 → Snapshot → Shell / HTTP。
 *
 * - 静态数组 fetcher：只同步一次，pollIntervalMs=0
 * - Closure fetcher：按 cron_poll_interval 秒 Polling；抛异常保留 Last Known Good
 * - createCronExecutor() 默认 CompositeExecutor；子类注入 Shell / HTTP 执行钩子
 * - onShutDown() 必须 cronManager->stop() 并注销 RuntimeRegistry cron snapshot
 * - runOnceNow() 转给 CronManager，引擎未启动时返回 FAILED
 *
 * 本类不再走 CrontabManager::addRule。进程内本地 crontab 见 {@see CronLocalProcess}，
 * 那是另一条产品线，不参与本引擎。
 *
 * @see CronManager
 * @see CronForkProcess
 * @see CronUrlProcess
 */
class CronProcess extends AbstractWorkerProcess
{

    /** exec_type=1：Shell / fork / swoolefy script。 */
    const EXEC_FORK_TYPE = 1;

    /** exec_type=2：HTTP URL。 */
    const EXEC_URL_TYPE = 2;
    /**
     * Worker args['task_list']：array 或 Closure。Closure 成功必须返回 array。
     *
     * @var mixed
     */
    protected $taskList;

    /**
     * 生产级 Cron 引擎。Worker Stop 时必须显式 stop()，不能只依赖析构。
     */
    protected ?CronManager $cronManager = null;

    /**
     * 读取 Worker args 的 task_list（静态数组或 DB fetcher Closure）。
     *
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
        $this->taskList = $this->getArgs()['task_list'] ?? [];
    }

    /**
     * 启动 Cron 引擎：初始同步 + Polling。
     *
     * 静态数组只同步一次；Closure 作为 fetcher，抛异常时保留 Last Known Good Runtime。
     */
    protected function runCronTask()
    {
        $this->cronManager = $this->createCronManager();
        RuntimeRegistry::registerCronSnapshot(fn (): array => $this->cronManager?->diagnostics() ?? ['enabled' => false]);
        $this->cronManager->start();
    }

    /**
     * 构造生产引擎。子类可覆盖 createCronExecutor() 注入 Shell / HTTP。
     */
    protected function createCronManager(): CronManager
    {
        $args = $this->getArgs();
        $pollSeconds = (int) ($args['cron_poll_interval'] ?? 20);
        $nodeId = $args['node_id'] ?? $args['cron_node_id'] ?? null;
        $heartbeatSeconds = CronNodeLiveness::normalizeInterval((int) ($args['heartbeat_interval'] ?? CronNodeLiveness::DEFAULT_INTERVAL));

        return new CronManager(
            fetcher: $this->createTaskFetcher(),
            executor: $this->createCronExecutor(),
            timer: new SwooleCronTimer(),
            clock: new SystemCronClock(),
            pollIntervalMs: $this->taskList instanceof \Closure ? max(5, $pollSeconds) * 1000 : 0,
            nodeId: $nodeId === null || $nodeId === '' ? null : (int) $nodeId,
            logWriter: function (ScheduleEvent|CronUrlTaskMetaDtoWorker $task, string $execBatchId, string $message, int $pid = 0, array $execution = []): void {
                $this->logCronTaskRuntime($task, $execBatchId, $message, $pid, $execution);
            },
            runOnceAck: $args['run_once_ack'] ?? null,
            heartbeatIntervalSeconds: $heartbeatSeconds,
            nodeHeartbeatAck: $args['node_heartbeat_ack'] ?? null,
        );
    }

    /**
     * 把 task_list 包成 CronManager fetcher。
     *
     * Closure：每次 Polling 调用；非 array 抛异常 → syncFromFetcher 视为 DB 故障。
     * 静态数组：闭包每次返回同一份，且 pollIntervalMs=0，不会周期重拉。
     *
     * @return callable():array<int, array<string, mixed>>
     */
    protected function createTaskFetcher(): callable
    {
        $taskList = $this->taskList;
        if ($taskList instanceof \Closure) {
            return static function () use ($taskList): array {
                $list = $taskList();
                if (!is_array($list)) {
                    throw new \RuntimeException('cron task_list fetcher 必须返回 array');
                }

                return $list;
            };
        }

        $static = is_array($taskList) ? $taskList : [];

        return static fn (): array => $static;
    }

    /**
     * 默认按 exec_type 分发 Shell / HTTP。子类可覆盖以注入 CronForkRunner / HTTP 回调。
     */
    protected function createCronExecutor(): CronExecutorInterface
    {
        return new CompositeExecutor(new ShellExecutor(), new HttpExecutor());
    }

    /**
     * 生产引擎实例（未 start 或已 stop 时为 null）。供控制面 / 单测调用 runOnceNow。
     */
    public function cronManager(): ?CronManager
    {
        return $this->cronManager;
    }

    /**
     * 忽略 expression，立刻用当前 Runtime 定义执行一次。见 {@see CronManager::runOnceNow()}。
     * 引擎未启动时返回 FAILED，不上抛。
     */
    public function runOnceNow(string $jobId): ExecutionResult
    {
        if ($this->cronManager === null) {
            return ExecutionResult::failed('runOnceNow: CronManager 未启动');
        }

        return $this->cronManager->runOnceNow($jobId);
    }

    /**
     * Worker Stop：停止 Polling / 心跳、清空 Job Timer、释放 Runtime。
     */
    public function onShutDown()
    {
        $this->cronManager?->stop();
        $this->cronManager = null;
        RuntimeRegistry::registerCronSnapshot(null);
        parent::onShutDown();
    }

    /**
     * 子类（CronForkProcess / CronUrlProcess）覆盖并调用 runCronTask()。
     * CronLocalProcess 不走本方法，仍用进程内 CrontabManager 单规则。
     *
     * @inheritDoc
     * @return mixed
     */
    public function run()
    {

    }

    /**
     * 将运行日志委托给业务 cron_db_log_class（实现 CronTaskInterface）。
     * 类缺失或抛异常只记 CRON_FORK_LOG，不得拖垮 Worker。
     *
     * @param ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask
     * @param string $execBatchId
     * @param string $message
     * @param int $pid
     * @param array<string, mixed> $execution
     * @return void
     */
    protected function logCronTaskRuntime(
        ScheduleEvent|CronUrlTaskMetaDtoWorker $scheduleTask,
        string                                 $execBatchId,
        string                                 $message,
        int                                    $pid = 0,
        array                                  $execution = [],
    )
    {
        $logClass = $scheduleTask->cron_db_log_class ?? null;
        if (!empty($logClass)) {
            try {
                /**
                 * @var $logRuntime CronTaskInterface
                 */
                $logRuntime = new $logClass();
                $logRuntime->logCronTaskRuntime($scheduleTask, $execBatchId, $message, $pid, $execution);
            }catch (\Throwable $e) {
                $errorMsg = "CronTaskInterface logCronTaskRuntime error: {$e->getMessage()}";
                $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
                $logger->error($errorMsg);
                fmtPrintError($errorMsg);
            }
        }
    }
}