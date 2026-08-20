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
 * - start()：初始 sync +（Closure fetcher 时）Polling tick + 节点心跳（立刻一次再按 interval tick）；失败保留空 Runtime，后续重试
 * - syncFromFetcher()：fetcher 抛异常 = DB 故障，保留 Last Known Good，绝不 clear all
 * - applyRows()：规范化 + nodeId 过滤后 Diff Apply；单行非法只跳过该行，
 *   已有 Runtime 时禁止误 DELETE（保留 Last Known Good）
 * - onTrigger()：先 arm 下一轮（异常隔离，失败则等 Polling reconcile），
 *   再 Guard / Window / Snapshot /（FAILED 时同轮 retry）；finally 释放 running
 * - runOnceNow()：忽略 expression / nextRunAt，立刻用当前 Runtime 定义跑一轮同一管线；不碰 Schedule Timer
 * - consumeRunOnceRequests()：一条 run_once 请求对应一次 Execution；仅 ack 单条 requestId
 * - 生产触发在 SwooleCronTimer 的 goApp 协程内执行（Swoole 6 proc_open/HTTP 要求）
 * - stop()：清 Polling、清心跳 tick、清全部 Job Timer、释放 Registry；必须显式调用
 *
 * 失败隔离（P0-5 / P0-6）：
 * - Job Exception ≠ Worker Exception：Executor / logWriter 异常不得拖垮其它 Job
 * - DB Failure ≠ Clear Runtime：空 desired 只有在 fetcher 成功返回 [] 时才全量 DELETE
 *
 * 调度不变量：
 * - Active Job = 恰好一个 Schedule Timer；Disabled / Deleted = 零个
 * - Enable ≠ Immediately Run，等待下一次合法 nextRunAt
 * - runOnceNow ≠ Enable Immediately Run：只额外执行一次，不改 nextRunAt，也不等于把 Enable 变成立刻跑
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

    /** 节点心跳 tick timerId；无 ack 或无 nodeId 时保持 0。 */
    private int $heartbeatTimerId = 0;

    /** 规范化后的心跳间隔（秒），默认 {@see CronNodeLiveness::DEFAULT_INTERVAL}。 */
    private readonly int $heartbeatIntervalSeconds;

    /** 最近一次心跳 ack 成功的 unix 秒（ack 抛异常则不更新）。 */
    private ?int $lastHeartbeatAt = null;

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
     * @param null|callable(ScheduleEvent|\Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker,string,string,int,array):void $logWriter 第 5 参为可选 Execution 结构化字段；旧 4 参闭包仍兼容
     * @param CronMetrics|null $metrics 空则接入 RuntimeRegistry::metrics()；指标关闭时内部为 no-op
     * @param null|callable(string,int,ExecutionResult,int):void $runOnceAck 消费一条手动请求后的确认 (jobId, cronTaskId, result, requestId)
     * @param int $heartbeatIntervalSeconds 节点心跳间隔（秒）；&lt;1 回退 {@see CronNodeLiveness::DEFAULT_INTERVAL}
     * @param null|callable(string,int):void $nodeHeartbeatAck 心跳回调 `(string $nodeId, int $interval=…): void`；第二参可选
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
        private $runOnceAck = null,
        int $heartbeatIntervalSeconds = CronNodeLiveness::DEFAULT_INTERVAL,
        private $nodeHeartbeatAck = null,
    ) {
        $this->heartbeatIntervalSeconds = CronNodeLiveness::normalizeInterval($heartbeatIntervalSeconds);
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
     * Worker Start：先尝试初始同步，再启动 Polling 与节点心跳。
     * 初始同步失败不建立 Schedule，但保留空 Runtime，由后续 Polling 重试。
     * 心跳在 start 时立刻打一次，避免 Admin 等到第一个 interval 才显示 online。
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
        $this->startNodeHeartbeat();
        $this->refreshMetrics();
    }

    /**
     * Worker Stop：停止 Polling / 心跳、清空全部 Job Timer、释放 Runtime。
     * 必须是显式生命周期操作。
     */
    public function stop(): void
    {
        if ($this->pollTimerId > 0) {
            $this->timer->clear($this->pollTimerId);
            $this->pollTimerId = 0;
        }
        if ($this->heartbeatTimerId > 0) {
            $this->timer->clear($this->heartbeatTimerId);
            $this->heartbeatTimerId = 0;
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
            $this->consumeRunOnceRequests($rows);

            return true;
        } catch (\Throwable $e) {
            // DB Failure ≠ Clear Runtime
            $this->lastConfigSyncError = $e->getMessage();
            $this->lastConfigSyncAt = $this->clock->now();
            $this->debug('Config sync failed, keep last known good runtime: ' . $e->getMessage());
            // DB 故障时仍尝试补回丢失的 Schedule Timer（例如上一轮 arm 失败）
            $this->reconcileMissingTimers();

            return false;
        }
    }

    /**
     * 将 fetcher 行规范化为 TaskDefinition，按 jobId 去重后做最小化 Diff Apply。
     *
     * 单行 fromArray() 失败只跳过该行，不影响本轮其它任务。
     * 若该行能解析出稳定 jobId 且 Runtime 已有该 Job，则禁止 DELETE，保留 Last Known Good。
     * 只有 DB 查询成功且任务明确不存在时才允许 DELETE。
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
        $invalidJobIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $definition = TaskDefinition::fromArray($row);
            } catch (\Throwable $e) {
                $this->debug('Skip invalid task: ' . $e->getMessage());
                $this->protectInvalidJobId($row, $invalidJobIds);
                continue;
            }
            if ($this->nodeId !== null && $definition->nodeId !== null && $definition->nodeId !== $this->nodeId) {
                continue;
            }
            $desired[$definition->jobId] = $definition;
        }

        $ops = $this->diff->diff($this->registry->definitions(), $desired, $invalidJobIds);
        foreach ($ops as $op) {
            $this->applyOp($op['op'], $op['jobId'], $op['definition']);
        }
        // Active Job 若丢失 Timer（arm 失败），由本轮 Polling 重新武装
        $this->reconcileMissingTimers();
        $this->refreshMetrics();
    }

    /**
     * 消费 fetcher 行上的手动执行标记。
     *
     * 一条 RunOnce Request 对应一次 Execution：按 requestId 逐条执行并 ack，
     * 禁止“执行一次却把同任务全部 pending 标记为已消费”。
     * 行字段优先 `run_once_request_ids` / `run_once_request_id`；
     * 兼容旧布尔 `run_once_requested`（视为 1 条匿名请求，requestId=0）。
     * 不改 Schedule Timer / nextRunAt。异常隔离，不得拖垮 Polling。
     * SKIPPED 不 ack、本轮停止该 Job 的后续请求，留给下一轮再试。
     *
     * @param list<array<string, mixed>> $rows
     */
    public function consumeRunOnceRequests(array $rows): void
    {
        $pendingByJob = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $requestIds = $this->pendingRunOnceRequestIds($row);
            if ($requestIds === []) {
                continue;
            }
            try {
                $definition = TaskDefinition::fromArray($row);
            } catch (\Throwable $e) {
                $this->debug('Skip run-once invalid task: ' . $e->getMessage());
                continue;
            }
            if ($this->nodeId !== null && $definition->nodeId !== null && $definition->nodeId !== $this->nodeId) {
                continue;
            }
            $jobId = $definition->jobId;
            if (!isset($pendingByJob[$jobId])) {
                $pendingByJob[$jobId] = [
                    'definition' => $definition,
                    'ids' => [],
                    'anonymousCount' => 0,
                ];
            }
            foreach ($requestIds as $requestId) {
                if ($requestId > 0) {
                    $pendingByJob[$jobId]['ids'][$requestId] = $requestId;
                } else {
                    $pendingByJob[$jobId]['anonymousCount']++;
                }
            }
        }

        foreach ($pendingByJob as $jobId => $item) {
            $queue = array_values($item['ids']);
            $anonymous = max(0, (int) $item['anonymousCount']);
            for ($i = 0; $i < $anonymous; $i++) {
                $queue[] = 0;
            }
            foreach ($queue as $requestId) {
                try {
                    $result = $this->runOnceNow($jobId);
                } catch (\Throwable $e) {
                    $result = ExecutionResult::failed('runOnceNow 异常已隔离: ' . $e->getMessage());
                }
                if (!$result->isCompleted()) {
                    // SKIPPED：不 ack，本轮不再继续消费该 Job 的后续请求
                    break;
                }
                $definition = $item['definition'];
                if (is_callable($this->runOnceAck) && $definition->cronTaskId > 0) {
                    try {
                        ($this->runOnceAck)($definition->jobId, $definition->cronTaskId, $result, $requestId);
                    } catch (\Throwable $e) {
                        $this->debug('runOnceAck failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Schedule Timer 触发入口。顺序固定：先武装下一次，再 Window / Guard / Executor。
     *
     * 先 arm 的原因：
     * 1. 执行耗时或异常都不能让 Scheduler 永久丢失下一轮
     * 2. 长任务期间下一计划点仍能触发，供 with_block_lapping SKIP
     * 3. SUCCESS / FAILED / SKIPPED / Exception 都不会破坏“Active=1 Timer”
     *
     * arm 发生不可预期异常时隔离并返回：此时 job->timerId 已为 0，
     * 不得继续 Execution，由下一轮 Config Polling reconcileMissingTimers() 补回。
     *
     * Job 已从 Registry 消失（DELETE 后未 running 已移除）则直接返回。
     *
     * 生产路径由 SwooleCronTimer 先 goApp 再进入本方法，因此 Shell/HTTP
     * 都在协程内。本方法本身不再二次 go()，以免 Guard 临界区与 finally
     * 和执行协程脱节。单测 ManualCronTimer 同步调用本方法。
     *
     * retry 在本方法内同步完成：不另武装 Timer、不新建 Snapshot、不释放 Guard。
     * 指标按「一轮 trigger」记一次，duration 含全部 attempt。
     */
    public function onTrigger(string $jobId): void
    {
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return;
        }
        $job->timerId = 0;
        $planned = $job->nextRunAt > 0 ? $job->nextRunAt : $this->clock->now();

        // 先安排下一次 one-shot：执行耗时/异常都不能让 Scheduler 永久丢失下一轮。
        // arm 失败必须隔离：此时 Timer=0，本轮不再执行，留给下一轮 Config Polling reconcile。
        try {
            if ($job->isSchedulable()) {
                $this->scheduler->arm($job, $planned);
            }
        } catch (\Throwable $e) {
            $this->debug('Timer arm failed, wait next config polling to reconcile: ' . $e->getMessage());
            $this->metrics->recordSchedulerError();
            return;
        }

        if (!$job->isSchedulable()) {
            return;
        }

        $this->runExecutionPipeline($job, $planned, 'trigger');
    }

    /**
     * 忽略 expression / nextRunAt，立刻用当前 RuntimeJob 定义执行一次。
     *
     * 与 onTrigger 共用 Window / Guard / Snapshot / retry / Executor / 日志 / 指标。
     * 与 onTrigger 的差异：
     * - 不 arm、不 clear Schedule Timer，已武装的 nextRunAt 保持不变
     * - 不是 Enable = Immediately Run（Enable 仍只等下一合法点）
     * - 忽略的是调度表达式，不是时间窗：仍应用 cron_between / cron_skip
     *
     * 重叠：with_block_lapping=1 且已 running → SKIPPED（与 trigger 相同）。
     * 缺失 / 停用 / 已删除：返回 FAILED，文案区分原因，不上抛 Worker。
     *
     * 协程：已在协程内则同步执行（与 onTrigger 一样，禁止二次 go()，以免 Guard/finally 脱节）。
     * 控制面 / HTTP 尚未进协程时，尽量 Coroutine::run 后再执行（Swoole 6 proc_open / HTTP hook）。
     * 单测无 Swoole 或无法新建调度器时回退同步。
     *
     * retry 字段仍作用于这一次：FAILED 在同一 Snapshot 内立即重试，不另武装 Timer。
     */
    public function runOnceNow(string $jobId): ExecutionResult
    {
        try {
            return $this->runInCoroutineIfNeeded(fn (): ExecutionResult => $this->doRunOnceNow($jobId));
        } catch (\Throwable $e) {
            // 控制面调用也必须失败隔离，不得拖垮 Worker / HTTP
            return ExecutionResult::failed('runOnceNow 异常已隔离: ' . $e->getMessage());
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
            'heartbeat_interval' => $this->heartbeatIntervalSeconds,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
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
     * 启动节点心跳：立刻 ack 一次，再按 heartbeat_interval 武装 tick。
     * 无 nodeHeartbeatAck 或无 nodeId 则跳过。
     */
    private function startNodeHeartbeat(): void
    {
        if (!is_callable($this->nodeHeartbeatAck) || $this->nodeId === null) {
            return;
        }
        $this->beatNode();
        $ms = $this->heartbeatIntervalSeconds * 1000;
        $this->heartbeatTimerId = $this->timer->tick($ms, function (): void {
            $this->beatNode();
        });
    }

    /**
     * 调用 nodeHeartbeatAck。异常隔离，不得拖垮 Polling / Job。
     * Worker 侧约定 `(string $nodeId): void`；额外传入 interval，闭包可选用第二参落库。
     */
    private function beatNode(): void
    {
        if (!is_callable($this->nodeHeartbeatAck) || $this->nodeId === null) {
            return;
        }
        try {
            ($this->nodeHeartbeatAck)((string) $this->nodeId, $this->heartbeatIntervalSeconds);
            $this->lastHeartbeatAt = $this->clock->now();
        } catch (\Throwable $e) {
            $this->debug('nodeHeartbeatAck failed: ' . $e->getMessage());
        }
    }

    /**
     * runOnceNow 的同步主体：校验 Runtime 后走同一执行管线，绝不改 Timer。
     */
    private function doRunOnceNow(string $jobId): ExecutionResult
    {
        if ($jobId === '') {
            return ExecutionResult::failed('runOnceNow: jobId 为空');
        }
        $job = $this->registry->get($jobId);
        if ($job === null) {
            return ExecutionResult::failed('runOnceNow: 任务不存在');
        }
        if ($job->deleted) {
            return ExecutionResult::failed('runOnceNow: 任务已删除');
        }
        if (!$job->definition->isEnabled()) {
            return ExecutionResult::failed('runOnceNow: 任务已停用');
        }

        // plannedAt 用当前时钟，表示「立刻跑」；不读取、不改写 nextRunAt
        return $this->runExecutionPipeline($job, $this->clock->now(), 'runOnceNow');
    }

    /**
     * Window → Guard → Snapshot → retry → 日志 / 指标。onTrigger 与 runOnceNow 共用。
     *
     * 调用方负责调度侧差异：onTrigger 先 arm；runOnceNow 不碰 Timer。
     *
     * @param string $source trigger | runOnceNow，仅影响开始日志文案
     */
    private function runExecutionPipeline(RuntimeJob $job, int $planned, string $source): ExecutionResult
    {
        $jobId = $job->jobId;
        $window = $this->timeWindow->evaluate($job->definition, $this->clock->now());
        if (!$window['allowed']) {
            $reason = '时间窗跳过: ' . $window['reason'];
            $this->recordSkip($job, $reason, $planned, $source);

            return ExecutionResult::skipped($reason);
        }

        if (!$this->guard->tryBegin($job)) {
            $reason = 'with_block_lapping 重叠跳过';
            $this->recordSkip($job, $reason, $planned, $source);

            return ExecutionResult::skipped($reason);
        }

        $snapshot = ExecutionSnapshot::create($job, $planned);
        $job->lastRunAt = $this->clock->now();
        $startedAt = $this->clock->now();
        $started = microtime(true);
        $result = ExecutionResult::failed('未执行');
        try {
            $this->writeLog(
                $snapshot,
                $this->formatStartMessage($job, $source),
                0,
                $this->executionPayload($snapshot, $source, ExecutionStatus::RUNNING, $startedAt),
            );
            $result = $this->runWithRetry($snapshot);
            $this->writeExecutionResult($job, $snapshot, $result, $source, $startedAt, $started);
            $this->metrics->recordRun($result->status, microtime(true) - $started);
        } catch (\Throwable $e) {
            // Job Exception ≠ Worker Exception（含 retry 循环外的兜底）
            $result = ExecutionResult::failed($e->getMessage());
            $this->writeExecutionResult($job, $snapshot, $result, $source, $startedAt, $started);
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

        return $result;
    }

    /**
     * 控制面可能不在协程内；Swoole 6 的 proc_open / HTTP 必须进协程。
     * 已在协程内则同步执行，禁止二次 go()（与 onTrigger 同一不变量）。
     *
     * @param callable():ExecutionResult $fn
     */
    private function runInCoroutineIfNeeded(callable $fn): ExecutionResult
    {
        if (!$this->shouldEnterNewCoroutine()) {
            return $fn();
        }

        $box = [ExecutionResult::failed('runOnceNow 未执行')];
        $runner = function () use ($fn, &$box): void {
            $box[0] = $fn();
        };
        try {
            if (method_exists(\Swoole\Coroutine::class, 'run')) {
                \Swoole\Coroutine::run($runner);
            } else {
                \Swoole\Coroutine\run($runner);
            }
        } catch (\Throwable $e) {
            $this->debug('runOnceNow 无法新建协程调度器，同步执行: ' . $e->getMessage());
            $box[0] = $fn();
        }

        return $box[0] instanceof ExecutionResult ? $box[0] : ExecutionResult::failed('runOnceNow 未执行');
    }

    /**
     * 仅当 Swoole 协程可用、当前 cid<0、且存在 Coroutine::run 时才新建调度器。
     */
    private function shouldEnterNewCoroutine(): bool
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return false;
        }
        try {
            if (\Swoole\Coroutine::getCid() > 0) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return method_exists(\Swoole\Coroutine::class, 'run') || function_exists('\\Swoole\\Coroutine\\run');
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
     * fingerprint 变化：先清旧 Timer，再换 TaskDefinition / Schedule，再按需封装唯一新 Timer。
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
     * 本轮 DB 行无法解析时，若能得到稳定 jobId 则保护已有 Runtime，禁止误 DELETE。
     * 其它节点的非法行不保护本节点 Job。
     *
     * @param array<string, mixed> $row
     * @param array<string, bool> $invalidJobIds
     */
    private function protectInvalidJobId(array $row, array &$invalidJobIds): void
    {
        try {
            $jobId = TaskDefinition::resolveJobId($row);
        } catch (\Throwable) {
            return;
        }
        if ($this->nodeId !== null) {
            $rowNodeId = $row['node_id'] ?? null;
            if ($rowNodeId !== null && $rowNodeId !== '' && (int) $rowNodeId !== $this->nodeId) {
                return;
            }
        }
        $invalidJobIds[$jobId] = true;
    }

    /**
     * Active Job 若没有有效 Schedule Timer，重新 arm。arm 失败隔离，不得拖垮 Polling。
     * 供 onTrigger arm 失败后的下一轮 Config Polling 收敛到“Active=1 Timer”。
     */
    private function reconcileMissingTimers(): void
    {
        foreach ($this->registry->all() as $job) {
            if (!$job->isSchedulable()) {
                continue;
            }
            if ($this->scheduler->activeTimerCount($job) > 0) {
                continue;
            }
            try {
                $this->scheduler->arm($job);
            } catch (\Throwable $e) {
                $this->debug('Reconcile timer arm failed: ' . $e->getMessage());
                $this->metrics->recordSchedulerError();
            }
        }
    }

    /**
     * 从 fetcher 行解析待消费的手动执行请求 ID 列表（FIFO）。
     *
     * 优先 `run_once_request_ids`；否则单个 `run_once_request_id`；
     * 再否则布尔 `run_once_requested`（兼容旧 fetcher），匿名 ID=0，可用
     * `run_once_pending_count` 表示同轮要执行的次数。
     *
     * @param array<string, mixed> $row
     * @return list<int>
     */
    private function pendingRunOnceRequestIds(array $row): array
    {
        if (isset($row['run_once_request_ids']) && is_array($row['run_once_request_ids'])) {
            $ids = [];
            foreach ($row['run_once_request_ids'] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        $single = (int) ($row['run_once_request_id'] ?? 0);
        if ($single > 0) {
            return [$single];
        }

        $requested = $row['run_once_requested'] ?? $row['run_once_requested_at'] ?? null;
        if (empty($requested)) {
            return [];
        }

        $count = (int) ($row['run_once_pending_count'] ?? 1);

        return array_fill(0, max(1, $count), 0);
    }

    /**
     * 同一 ExecutionSnapshot 内按 retry 重试 FAILED。
     *
     * 语义：retry=0 只跑 1 次；retry=N 最多 1+N 次（首次 + N 次重试）。
     * 只重试 FAILED 与 TIMEOUT（含 Executor 抛出后隔离成的 FAILED）；SUCCESS 立即结束。
     * SKIPPED 由 Window / Guard 在进入本方法前处理，不会走到这里。
     * 无 retry_delay 字段，失败后立即重试，不另武装 Timer。
     */
    private function runWithRetry(ExecutionSnapshot $snapshot): ExecutionResult
    {
        $maxAttempts = $snapshot->definition->maxAttempts();
        $result = ExecutionResult::failed('未执行');
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // 全程使用同一冻结 Snapshot：Config Update 不得改写本轮 command / url
                $result = $this->executor->run($snapshot);
            } catch (\Throwable $e) {
                // 单次 attempt 异常隔离为 FAILED，可继续重试，不得拖垮 Worker
                $result = ExecutionResult::failed($e->getMessage());
            }
            if (!$result->isFailed() && !$result->isTimeout()) {
                return $result;
            }
            if ($attempt < $maxAttempts) {
                $this->writeLog($snapshot, sprintf(
                    '【%s】第 %d/%d 次失败，立即重试: %s',
                    $snapshot->definition->cronName,
                    $attempt,
                    $maxAttempts,
                    $result->message,
                ), $result->pid);
            }
        }

        return $result;
    }

    /**
     * 时间窗或重叠导致的 SKIP：写一条 SKIPPED Execution 并计入 skipped 指标，不调用 Executor。
     */
    private function recordSkip(RuntimeJob $job, string $reason, int $planned, string $source): void
    {
        $snapshot = ExecutionSnapshot::create($job, $planned);
        $now = $this->clock->now();
        $this->writeLog(
            $snapshot,
            sprintf('【%s】SKIP %s', $job->definition->cronName, $reason),
            0,
            [
                'status' => ExecutionStatus::SKIPPED,
                'trigger_type' => ExecutionStatus::triggerType($source),
                'scheduled_at' => date('Y-m-d H:i:s', $snapshot->plannedAt),
                'finished_at' => date('Y-m-d H:i:s', $now),
                'duration_ms' => 0,
            ],
        );
        $this->metrics->recordRun(ExecutionResult::SKIPPED);
    }

    /**
     * 将本轮终态 ExecutionResult 写入 cron_task_log（同一 exec_batch_id upsert）。
     */
    private function writeExecutionResult(
        RuntimeJob $job,
        ExecutionSnapshot $snapshot,
        ExecutionResult $result,
        string $source,
        int $startedAt,
        float $startedMicro,
    ): void {
        $durationMs = (int) max(0, round((microtime(true) - $startedMicro) * 1000));
        $payload = $this->executionPayload($snapshot, $source, ExecutionStatus::fromResult($result), $startedAt);
        $payload['finished_at'] = date('Y-m-d H:i:s', $this->clock->now());
        $payload['duration_ms'] = $durationMs;
        $payload['exit_code'] = $result->exitCode;
        $payload['http_status'] = $result->httpStatus;
        $this->writeLog($snapshot, $this->formatResultMessage($job, $result), $result->pid, $payload);
    }

    /**
     * 构造落库用的结构化 Execution 字段（不含 message）。
     *
     * @return array<string, mixed>
     */
    private function executionPayload(ExecutionSnapshot $snapshot, string $source, int $status, int $startedAt): array
    {
        return [
            'status' => $status,
            'trigger_type' => ExecutionStatus::triggerType($source),
            'scheduled_at' => date('Y-m-d H:i:s', $snapshot->plannedAt),
            'started_at' => date('Y-m-d H:i:s', $startedAt),
        ];
    }

    /**
     * 执行期日志。logWriter 异常只记 debug，不得回传到 onTrigger。
     *
     * @param array<string, mixed> $execution status / duration_ms / exit_code 等结构化字段
     */
    private function writeLog(ExecutionSnapshot $snapshot, string $message, int $pid = 0, array $execution = []): void
    {
        $this->invokeLogWriter($snapshot->definition->toLogDto(), $snapshot->execBatchId, $message, $pid, $execution);
    }

    /**
     * ADD/UPDATE/DELETE/ENABLE/DISABLE 的配置变更日志，execBatchId 通常为空串。
     * 配置变更不是 Execution，不写 status，避免污染 taskStats。
     */
    private function writeDefinitionLog(TaskDefinition $definition, string $execBatchId, string $message): void
    {
        $this->invokeLogWriter($definition->toLogDto(), $execBatchId, $message, 0, []);
    }

    /**
     * 调用 logWriter：优先传 5 参（含 Execution 字段）；旧 4 参闭包回退，避免 ArgumentCountError。
     *
     * @param array<string, mixed> $execution
     */
    private function invokeLogWriter(object $dto, string $execBatchId, string $message, int $pid, array $execution): void
    {
        if ($this->logWriter === null) {
            return;
        }
        try {
            try {
                ($this->logWriter)($dto, $execBatchId, $message, $pid, $execution);
            } catch (\ArgumentCountError) {
                ($this->logWriter)($dto, $execBatchId, $message, $pid);
            }
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
     * 开始执行日志。runOnceNow 标明忽略了本轮调度点，便于和 trigger 区分。
     */
    private function formatStartMessage(RuntimeJob $job, string $source): string
    {
        if ($source === 'runOnceNow') {
            return sprintf(
                '【%s】runOnceNow 开始执行（忽略 expression / nextRunAt） cron_expression=%s',
                $job->definition->cronName,
                $job->definition->expression,
            );
        }

        return sprintf('【%s】开始执行 cron_expression=%s', $job->definition->cronName, $job->definition->expression);
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
