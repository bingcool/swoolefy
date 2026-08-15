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

/**
 * Runtime 中的单个 Job 可变状态（与不可变 TaskDefinition 成对）。
 *
 * 本对象只活在当前 Worker 的 RuntimeJobRegistry 里，不跨进程、不落库。
 * ConfigDiff 比较的是 $definition，Timer / 执行态挂在本对象上。
 *
 * Timer 不变量（由 CronScheduler 维护，本类只持有 timerId）：
 * - Active（status=STATUS_ENABLED 且 deleted=false）= 恰好一个有效 Schedule Timer
 * - Disabled / Deleted = 零个 Schedule Timer
 * - timerId=0 表示当前没有武装；one-shot 触发后 CronManager::onTrigger() 会先置 0 再 arm
 *
 * 执行态：
 * - running / runningCount 只由 ExecutionGuard 在临界区内改写
 * - with_block_lapping=1 时 running=true 则下一轮 SKIP
 * - with_block_lapping=0 时 runningCount 可 > 1
 * - deleted=true 且仍 running 时，当前 Execution 结束后才从 Registry 移除
 *
 * UPDATE 会替换 $definition / $schedule，但不改写已发出的 ExecutionSnapshot。
 *
 * @see RuntimeJobRegistry
 * @see CronScheduler
 * @see ExecutionGuard
 */
final class RuntimeJob
{
    /** CronScheduler 武装的 one-shot timerId；0 表示未武装或刚触发尚未重新 arm。 */
    public int $timerId = 0;

    /** 下一次计划 unix 秒（严格晚于武装基准）。Enable ≠ Immediately Run。 */
    public int $nextRunAt = 0;

    /** 是否至少有一个 Execution 在跑。由 ExecutionGuard 维护。 */
    public bool $running = false;

    /**
     * 当前并发执行实例数。with_block_lapping=0 时允许 > 1。
     */
    public int $runningCount = 0;

    /** 最近一次实际进入 Executor 的 unix 秒（SKIP 不更新）。 */
    public int $lastRunAt = 0;

    /** 最近一次 Execution finally 的 unix 秒。 */
    public int $lastFinishAt = 0;

    /**
     * ConfigDiff::DELETE 后为 true。进行中的 Execution 不杀，结束后再 remove。
     */
    public bool $deleted = false;

    public function __construct(
        public string $jobId,
        public TaskDefinition $definition,
        public ScheduleInterface $schedule,
    ) {
    }

    /**
     * 是否仍应持有 Schedule Timer（启用且未删除）。
     */
    public function isSchedulable(): bool
    {
        return !$this->deleted && $this->definition->isEnabled();
    }

    /**
     * 诊断用的单任务视图，不含命令明文以外的敏感扩展。
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'id' => $this->definition->cronTaskId > 0 ? $this->definition->cronTaskId : $this->jobId,
            'name' => $this->definition->cronName,
            'status' => $this->definition->status,
            'nextRunAt' => $this->nextRunAt,
            'lastRunAt' => $this->lastRunAt,
            'running' => $this->running,
            'timerId' => $this->timerId,
        ];
    }
}
