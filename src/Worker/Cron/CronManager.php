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
use Swoolefy\Exception\CronException;

/**
 * Cron 生产引擎门面：把 Config Sync / Runtime / Scheduler / Execution / Log / Metrics 串成一条管线。
 *
 * Web Admin 只改 cron_task（或静态 task_list），本对象通过 Polling + ConfigDiff 驱动 Worker。
 * 本类是编排者，不自己解析 expression、不自己发 HTTP/Shell、不自己比较 fingerprint。
 *
 * 分层边界（禁止互相越权）：
 * - ConfigDiff：纯比较器，产出 ADD/UPDATE/DELETE/ENABLE/DISABLE/NOOP
 * - RuntimeJobRegistry：进程内 Job 状态，不含 Timer / 执行细节
 * - CronScheduler + CronTimerInterface：只武装 / 清除 one-shot Timer
 * - ExecutionGuard：with_block_lapping 临界区
 * - ExecutionSnapshot：本轮冻结定义，Config Update 不得改写
 * - CronExecutorInterface：只负责“怎么执行”，失败必须返回 FAILED 而不是上抛 Worker
 * - TimeWindowFilter：只决定本轮 SKIP，不改 expression
 *
 * 生命周期：
 * - start()：初始 sync +（Closure fetcher 时）Polling tick；失败保留空 Runtime，后续重试
 * - syncFromFetcher()：fetcher 抛异常 = DB 故障，保留 Last Known Good，绝不 clear all
 * - applyRows()：规范化 + nodeId 过滤后 Diff Apply；单行非法只跳过该行
 * - onTrigger()：先 arm 下一轮，再 Guard / Window / Executor；finally 释放 running
 * - 生产触发在 SwooleCronTimer 的 goApp 协程内执行（Swoole 6 proc_open/HTTP 要求）
 * - stop()：清 Polling、清全部 Job Timer、释放 Registry；必须显式调用
 *
 * 失败隔离（P0-5 / P0-6）：
 * - Job Exception ≠ Worker Exception：Executor / logWriter 异常不得拖垮其它 Job
 * - DB Failure ≠ Clear Runtime：空 desired 只有在 fetcher 成功返回 [] 时才全量 DELETE
 *
 * 调度不变量：
 * - Active Job = 恰好一个 Schedule Timer；Disabled / Deleted = 零个
 * - Enable ≠ Immediately Run，等待下一次合法 nextRunAt
 * - DELETE 不杀进行中的 Execution；结束后再从 Registry 移除
 *
 * @see ConfigDiff
 * @see CronScheduler
 * @see ExecutionGuard
 * @see ExecutionSnapshot
 */
final class CronManager
{
    private readonly RuntimeJobRegistry $registry;
    private readonly CronScheduler $scheduler;
    private readonly ConfigDiff $diff;
    private readonly ExecutionGuard $guard;
    private readonly TimeWindowFilter $timeWindow;
    private readonly ExpressionParser $parser;
    private readonly CronMetrics $metrics;

    /** Config Polling 的 tick timerId；静态数组 fetcher 时保持 0（只同步一次）。 */
    private int $pollTimerId = 0;

    /** 最近一次 sync（成功或失败）的 unix 秒，供诊断。 */
    private ?int $lastConfigSyncAt = null;

    /** 最近一次 fetcher 异常文案；成功同步后清空。 */
    private ?string $lastConfigSyncError = null;

    /** 防止 start() 重复武装 Polling。 */
    private bool $started = false;

    /**
     * @param callable():array<int, array<string, mixed>> $fetcher 成功必须返回数组；抛异常视为 DB 故障
     * @param CronExecutorInterface $executor 由 CronProcess 子类注入 Shell / HTTP（含 CronForkRunner）
     * @param CronTimerInterface $timer 生产用 SwooleCronTimer，单测注入 ManualCronTimer
     * @param CronClockInterface $clock 生产用 SystemCronClock，单测注入 FrozenCronClock
     * @param int $pollIntervalMs Closure fetcher 的轮询间隔；静态数组传 0 表示不 Polling
     * @param int|null $nodeId 非空时丢弃其它节点任务（过滤发生在 Diff 之前）
     * @param null|callable(ScheduleEvent|\Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker,string,string,int):void $logWriter
     * @param CronMetrics|null $metrics 空则接入 RuntimeRegistry::metrics()；指标关闭时内部为 no-op
     */
    public function __construct(
        private $fetcher,
        private readonly CronExecutorInterface $executor,
        private readonly CronTimerInterface $timer,
        private readonly CronClockInterface $clock,
        private readonly int $pollIntervalMs = 20000,
        private readonly ?int $nodeId = null,
        private $logWriter = null,
        ?CronMetrics $metrics = null,
    ) {
        $this->registry = new RuntimeJobRegistry();
        $this->scheduler = new CronScheduler($this->timer, $this->clock);
        $this->diff = new ConfigDiff();
        $this->guard = new ExecutionGuard();
        $this->timeWindow = new TimeWindowFilter();
        $this->parser = new ExpressionParser();
        $this->metrics = $metrics ?? new CronMetrics(RuntimeRegistry::metrics());
        $this->scheduler->setTriggerHandler(fn (string $jobId) => $this->onTrigger($jobId));
    }

    /**
     * Worker Start：先尝试初始同步，再启动 Polling。
     * 初始同步失败不建立 Schedule，但保留空 Runtime，由后续 Polling 重试。
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->syncFromFetcher();
        if ($this->pollIntervalMs > 0) {
            $this->pollTimerId = $this->timer->tick($this->pollIntervalMs, function (): void {
                $this->syncFromFetcher();
            });
        }
        $this->refreshMetrics();
    }

    /**
     * Worker Stop：停止 Polling、清空全部 Job Timer、释放 Runtime。
     * 必须是显式生命周期操作。
     */
    public function stop(): void
    {
        if ($this->pollTimerId > 0) {
            $this->timer->clear($this->pollTimerId);
            $this->pollTimerId = 0;
        }
        $this->scheduler->clearAll($this->registry);
        $this->timer->clearAll();
        $this->registry->clear();
        $this->started = false;
        $this->refreshMetrics();
    }

    /**
     * 从 fetcher 拉取并 Diff Apply。
     *
     * fetcher 抛异常 = DB 故障：保留 Last Known Good Runtime，绝不 clear all。
     * fetcher 成功返回空数组 = 配置侧确实没有任务，按 DELETE 收敛。
     */
    public function syncFromFetcher(): bool
    {
        try {
            $rows = ($this->fetcher)();
            if (!is_array($rows)) {
                throw new CronException('Cron fetcher 必须返回 array');
            }
            $this->lastConfigSyncError = null;
            $this->lastConfigSyncAt = $this->clock->now();
            $this->applyRows($rows);

            return true;
        } catch (\Throwable $e) {
            // P0-6：DB Failure ≠ Clear Runtime
            $this->lastConfigSyncError = $e->getMessage();
            $this->lastConfigSyncAt = $this->clock->now();
            $this->debug('Config sync failed, keep last known good runtime: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 将 fetcher 行规范化为 TaskDefinition，按 jobId 去重后做最小化 Diff Apply。
     *
     * 单行 fromArray() 失败只跳过该行，不影响本轮其它任务。
     * nodeId 过滤在进入 ConfigDiff 之前完成：改挂其它节点的任务会从 $desired
     * 消失，从而被分类为 DELETE（停止本节点未来调度）。
     *
     * 同一 cron_task_id / cron_name 出现多行时，后写覆盖先写（以 jobId 为键）。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function applyRows(array $rows): void
    {
        $desired = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $definition = TaskDefinition::fromArray($row);
            } catch (\Throwable $e) {
                $this->debug('Skip invalid task: ' . $e->getMessage());
                continue;
            }
            if ($this->nodeId !== null && $definition->nodeId !== null && $definition->nodeId !== $this->nodeId) {
                continue;
            }
            $desired[$definition->jobId] = $definition;
        }

        $ops = $this->diff->diff($this->registry->definitions(), $desired);
        foreach ($ops as $op) {
            $this->applyOp($op['op'], $op['jobId'], $op['definition']);
        }
        $this->refreshMetrics();
    }

    /**
     * Schedule Timer 触发入口。顺序固定：先武装下一次，再 Window / Guard / Executor。
     *
     * 先 arm 的原因：
     * 1. 执行耗时或异常都不能让 Scheduler 永久丢失下一轮
     * 2. 长任务期间下一计划点仍能触发，供 with_block_lapping SKIP
     * 3. SUCCESS / FAILED / SKIPPED / Exception 都不会破坏“Active=1 Timer”
     *
     * Job 已从 Registry 消失（DELETE 后未 running 已移除）则直接返回。
     *
     * 生产路径由 SwooleCronTimer 先 goApp 再进入本方法，因此 Shell/HTTP
     * 都在协程内。本方法本身不再二次 go()，以免 Guard 临界区与 finally
     * 和执行协程脱节。单测 ManualCronTimer 同步调用本方法。
     */
    public function onTrigger(string $jobId): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return;
        }
        $job->timerId = 0;
        $planned = $job->nextRunAt > 0 ? $job->nextRunAt : $this->clock->now();

        // 先安排下一次 one-shot：执行耗时/异常都不能让 Scheduler 永久丢失下一轮
        if ($job->isSchedulable()) {
            $this->scheduler->arm($job, $planned);
        }

        if (!$job->isSchedulable()) {
            return;
        }

        $window = $this->timeWindow->evaluate($job->definition, $this->clock->now());
        if (!$window['allowed']) {
            $this->recordSkip($job, '时间窗跳过: ' . $window['reason']);
            return;
        }

        if (!$this->guard->tryBegin($job)) {
            $this->recordSkip($job, 'with_block_lapping 重叠跳过');
            return;
        }

        $snapshot = ExecutionSnapshot::create($job, $planned);
        $job->lastRunAt = $this->clock->now();
        $started = microtime(true);
        try {
            $this->writeLog($snapshot, sprintf('【%s】开始执行 cron_expression=%s', $job->definition->cronName, $job->definition->expression));
            $result = $this->executor->run($snapshot);
            $this->writeLog($snapshot, $this->formatResultMessage($job, $result), $result->pid);
            $this->metrics->recordRun($result->status, microtime(true) - $started);
        } catch (\Throwable $e) {
            // P0-5：Job Exception ≠ Worker Exception
            $result = ExecutionResult::failed($e->getMessage());
            $this->writeLog($snapshot, sprintf('【%s】执行异常已隔离: %s', $job->definition->cronName, $e->getMessage()));
            $this->metrics->recordRun(ExecutionResult::FAILED, microtime(true) - $started);
        } finally {
            $this->guard->end($this->registry->get($jobId));
            $alive = $this->registry->get($jobId);
            if ($alive !== null) {
                $alive->lastFinishAt = $this->clock->now();
                // DELETE 后当前 Execution 结束后再释放 Runtime，避免中途杀掉
                if ($alive->deleted && !$alive->running) {
                    $this->registry->remove($jobId);
                }
            }
            $this->refreshMetrics();
        }
    }

    /**
     * Worker 诊断快照：Job 计数、最近同步、每任务 nextRunAt / running。
     * 由 RuntimeRegistry::registerCronSnapshot() 暴露给 RuntimeDiagnostics。
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $jobs = [];
        foreach ($this->registry->all() as $job) {
            $jobs[] = $job->diagnostics();
        }

        return [
            'job_count' => $this->registry->count(),
            'enabled_count' => $this->registry->enabledCount(),
            'running_count' => $this->registry->runningCount(),
            'last_config_sync' => $this->lastConfigSyncAt,
            'last_config_sync_error' => $this->lastConfigSyncError,
            'jobs' => $jobs,
        ];
    }

    /**
     * 进程内 Runtime 注册表（单测断言 Job 是否仍在、定义是否已换）。
     */
    public function registry(): RuntimeJobRegistry
    {
        return $this->registry;
    }

    /**
     * 调度器（单测一般走 timerCountFor()，不必直接操作）。
     */
    public function scheduler(): CronScheduler
    {
        return $this->scheduler;
    }

    /**
     * 最近一次 Config Sync 失败原因；成功后为 null。
     */
    public function lastConfigSyncError(): ?string
    {
        return $this->lastConfigSyncError;
    }

    /**
     * 最近一次 Config Sync 的 unix 秒（成功或失败都会更新）。
     */
    public function lastConfigSyncAt(): ?int
    {
        return $this->lastConfigSyncAt;
    }

    /**
     * 当前 Active Job 的有效 Timer 数（用于不变量断言）。
     */
    public function timerCountFor(string $jobId): int
    {
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return 0;
        }

        return $this->scheduler->activeTimerCount($job);
    }

    /**
     * 将单条 Diff op 落到 Runtime / Timer。NOOP 与未知 op 丢弃。
     *
     * ADD/UPDATE/ENABLE 的 expression 非法时只记日志并跳过该 op，
     * 不影响同轮其它 Job（失败隔离）。
     *
     * @param TaskDefinition|null $definition ADD/UPDATE/ENABLE/DISABLE 为 desired；DELETE 为 runtime 当前值
     */
    private function applyOp(string $op, string $jobId, ?TaskDefinition $definition): void
    {
        match ($op) {
            ConfigDiff::ADD => $this->applyAdd($definition),
            ConfigDiff::UPDATE => $this->applyUpdate($jobId, $definition),
            ConfigDiff::DELETE => $this->applyDelete($jobId),
            ConfigDiff::ENABLE => $this->applyEnable($jobId, $definition),
            ConfigDiff::DISABLE => $this->applyDisable($jobId, $definition),
            default => null,
        };
    }

    /**
     * 新建 RuntimeJob；仅 STATUS_ENABLED 时武装 Timer。Disable 状态的 ADD 只入 Registry。
     */
    private function applyAdd(?TaskDefinition $definition): void
    {
        if ($definition === null) {
            return;
        }
        try {
            $schedule = $this->parser->parse($definition->expression, $definition->timezone);
        } catch (\Throwable $e) {
            $this->debug('ADD 校验失败: ' . $e->getMessage());
            return;
        }
        $job = new RuntimeJob($definition->jobId, $definition, $schedule);
        $this->registry->put($job);
        if ($job->isSchedulable()) {
            $this->scheduler->arm($job);
        }
        $this->writeDefinitionLog($definition, '', sprintf('【%s】ADD 注册定时任务', $definition->cronName));
    }

    /**
     * fingerprint 变化：先清旧 Timer，再换 TaskDefinition / Schedule，再按需武装唯一新 Timer。
     * 进行中的 Execution 继续使用已冻结的 ExecutionSnapshot，不受本轮换定义影响。
     */
    private function applyUpdate(string $jobId, ?TaskDefinition $definition): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null || $definition === null) {
            return;
        }
        try {
            $schedule = $this->parser->parse($definition->expression, $definition->timezone);
        } catch (\Throwable $e) {
            $this->debug('UPDATE 校验失败: ' . $e->getMessage());
            return;
        }
        // 先清旧 Timer，再换定义，再武装唯一新 Timer，避免双 Timer
        $this->scheduler->clear($job);
        $job->definition = $definition;
        $job->schedule = $schedule;
        if ($job->isSchedulable()) {
            $this->scheduler->arm($job);
        }
        $this->writeDefinitionLog($definition, '', sprintf('【%s】UPDATE 已重新注册定时任务', $definition->cronName));
    }

    /**
     * desired 缺席：停止未来调度。running 时只打 deleted 标记，等 onTrigger finally 再移除。
     */
    private function applyDelete(string $jobId): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return;
        }
        $this->scheduler->clear($job);
        $job->deleted = true;
        $definition = $job->definition;
        // 当前 Execution 继续；只移除未来调度。若未在跑则立即释放。
        if (!$job->running) {
            $this->registry->remove($jobId);
        }
        $this->writeDefinitionLog($definition, '', sprintf('【%s】DELETE 已停止未来调度', $definition->cronName));
    }

    /**
     * STATUS_ENABLED → STATUS_DISABLED：清 Timer、保留 RuntimeJob，便于再次 ENABLE。
     * 与 DELETE 不同：Job 不会从 Registry 移除。
     */
    private function applyDisable(string $jobId, ?TaskDefinition $definition): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return;
        }
        $this->scheduler->clear($job);
        if ($definition !== null) {
            $job->definition = $definition;
        }
        $this->writeDefinitionLog($job->definition, '', sprintf('【%s】DISABLE 已停止未来调度', $job->definition->cronName));
    }

    /**
     * STATUS_DISABLED → STATUS_ENABLED：换定义后武装下一合法 nextRunAt。
     * Enable ≠ Immediately Run，不会立刻调用 Executor。
     */
    private function applyEnable(string $jobId, ?TaskDefinition $definition): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null || $definition === null) {
            return;
        }
        try {
            $schedule = $this->parser->parse($definition->expression, $definition->timezone);
        } catch (\Throwable $e) {
            $this->debug('ENABLE 校验失败: ' . $e->getMessage());
            return;
        }
        $job->definition = $definition;
        $job->schedule = $schedule;
        $job->deleted = false;
        // Enable ≠ Immediately Run：等待下一次合法 nextRunAt
        $this->scheduler->arm($job);
        $this->writeDefinitionLog($definition, '', sprintf('【%s】ENABLE 已恢复调度', $definition->cronName));
    }

    /**
     * 时间窗或重叠导致的 SKIP：写 cron_task_log 并计入 skipped 指标，不调用 Executor。
     */
    private function recordSkip(RuntimeJob $job, string $reason): void
    {
        $snapshot = ExecutionSnapshot::create($job, $job->nextRunAt);
        $this->writeLog($snapshot, sprintf('【%s】SKIP %s', $job->definition->cronName, $reason));
        $this->metrics->recordRun(ExecutionResult::SKIPPED);
    }

    /**
     * 执行期日志。logWriter 异常只记 debug，不得回传到 onTrigger（P0-5）。
     */
    private function writeLog(ExecutionSnapshot $snapshot, string $message, int $pid = 0): void
    {
        if ($this->logWriter === null) {
            return;
        }
        try {
            ($this->logWriter)($snapshot->definition->toLogDto(), $snapshot->execBatchId, $message, $pid);
        } catch (\Throwable $e) {
            $this->debug('cron_task_log 写入失败（已隔离）: ' . $e->getMessage());
        }
    }

    /**
     * ADD/UPDATE/DELETE/ENABLE/DISABLE 的配置变更日志，execBatchId 通常为空串。
     */
    private function writeDefinitionLog(TaskDefinition $definition, string $execBatchId, string $message): void
    {
        if ($this->logWriter === null) {
            return;
        }
        try {
            ($this->logWriter)($definition->toLogDto(), $execBatchId, $message, 0);
        } catch (\Throwable $e) {
            $this->debug('cron_task_log 写入失败（已隔离）: ' . $e->getMessage());
        }
    }

    /**
     * 将 ExecutionResult 格式化为 cron_task_log 文案（保留 cronName / status / message）。
     */
    private function formatResultMessage(RuntimeJob $job, ExecutionResult $result): string
    {
        return sprintf(
            '【%s】%s %s',
            $job->definition->cronName,
            $result->status,
            $result->message,
        );
    }

    /**
     * 把 Registry 计数刷到 RuntimeMetrics Gauge。start / stop / sync / 每轮 Execution 后调用。
     */
    private function refreshMetrics(): void
    {
        $this->metrics->recordJobs(
            $this->registry->count(),
            $this->registry->enabledCount(),
            $this->registry->runningCount(),
        );
    }

    /**
     * CRON_DEBUG 时打印，并写入 CRON_FORK_LOG。logger 缺失时静默。
     */
    private function debug(string $msg): void
    {
        if (function_exists('env') && env('CRON_DEBUG')) {
            fmtPrintNote($msg);
        }
        $logger = LogManager::getInstance()->getLogger(LogManager::CRON_FORK_LOG);
        $logger?->info($msg);
    }
}
