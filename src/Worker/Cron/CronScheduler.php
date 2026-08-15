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
 * One-shot Timer 调度器。
 *
 * 本类只负责“何时触发”，不解析 expression、不执行任务、不写日志。
 * nextRunAt 由 ScheduleInterface 计算；真正的 after/clear 走 CronTimerInterface。
 * 触发回调由 CronManager::setTriggerHandler() 注入，避免 Scheduler 依赖 Executor。
 *
 * 每个 Active Job 最多持有一个 Schedule Timer。CronManager::onTrigger() 约定：
 * 触发后先 arm 下一次，再进入执行管线，从而：
 * 1. 避免 finish_time + interval 漂移（对齐 unix 网格 / Cron 下一合法点）
 * 2. 长任务期间下一计划点仍能触发（供 with_block_lapping SKIP）
 * 3. 本轮 SUCCESS / FAILED / SKIPPED / Exception 都不会丢失后续调度
 *
 * arm() 内部先 clear 再 after，保证 UPDATE / 重复 arm 不会双 Timer。
 * Disabled / Deleted 必须 clear()；Worker Stop 走 clearAll()。
 *
 * 协程不变量：生产 SwooleCronTimer 必须把 after 回调投进协程（goApp）。
 * Swoole 6 的 proc_open / HTTP 不能在进程事件循环里调用。
 * 单测 ManualCronTimer 保持同步触发，便于断言 Guard / Snapshot。
 *
 * @see ScheduleInterface
 * @see CronTimerInterface
 * @see CronManager::onTrigger()
 */
final class CronScheduler
{
    /** @var null|callable(string):void */
    private $onTrigger;

    /**
     * @param CronTimerInterface $timer 生产 SwooleCronTimer / 单测 ManualCronTimer
     * @param CronClockInterface $clock 用于计算 delayMs = nextRunAt - now
     */
    public function __construct(
        private readonly CronTimerInterface $timer,
        private readonly CronClockInterface $clock,
    ) {
    }

    /**
     * 注册触发回调。由 CronManager 注入，避免 Scheduler 直接依赖执行细节。
     *
     * @param callable(string):void $onTrigger
     */
    public function setTriggerHandler(callable $onTrigger): void
    {
        $this->onTrigger = $onTrigger;
    }

    /**
     * 清除旧 Timer 后按 Schedule 计算 nextRunAt 并创建唯一 one-shot Timer。
     *
     * @param int|null $fromTimestamp 为空则使用当前时钟
     */
    public function arm(RuntimeJob $job, ?int $fromTimestamp = null): void
    {
        $this->clear($job);
        if (!$job->isSchedulable()) {
            return;
        }

        $from = $fromTimestamp ?? $this->clock->now();
        $next = $job->schedule->calculateNextRunAt($from);
        $job->nextRunAt = $next;
        $delayMs = max(1, ($next - $this->clock->now()) * 1000);
        $jobId = $job->jobId;

        // 核心不变量：Active Job = 恰好一个 Schedule Timer
        // 生产 SwooleCronTimer 会把本回调 goApp 进协程，再进入 onTrigger
        $job->timerId = $this->timer->after($delayMs, function () use ($jobId): void {
            if ($this->onTrigger !== null) {
                ($this->onTrigger)($jobId);
            }
        });
    }

    /**
     * 清除该 Job 的 Schedule Timer。Disabled / Deleted 必须走到这里。
     */
    public function clear(RuntimeJob $job): void
    {
        if ($job->timerId > 0) {
            $this->timer->clear($job->timerId);
            $job->timerId = 0;
        }
    }

    /**
     * Worker Stop / Restart：显式清掉全部 Job Timer。
     */
    public function clearAll(RuntimeJobRegistry $registry): void
    {
        foreach ($registry->all() as $job) {
            $this->clear($job);
        }
    }

    /**
     * 当前 Job 持有的有效 Timer 数量（0 或 1）。
     */
    public function activeTimerCount(RuntimeJob $job): int
    {
        if ($job->timerId > 0 && $this->timer->exists($job->timerId)) {
            return 1;
        }

        return 0;
    }
}
