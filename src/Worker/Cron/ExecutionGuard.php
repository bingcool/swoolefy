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
 * 执行并发守卫。
 *
 * 边界：只改 RuntimeJob::$running / $runningCount，不碰 Timer、不调用 Executor。
 * CronManager::onTrigger() / runOnceNow() 在 TimeWindowFilter 通过之后、创建 Snapshot 之前调用。
 *
 * with_block_lapping=1 时，同一 Job 任意时刻最多一个 Running Execution。
 * check running + mark running 必须在同一临界区完成，中间不可 yield
 *（不可 I/O、sleep、Channel、go()），否则两个协程会同时看到 running=false 并双开。
 *
 * with_block_lapping=0 时允许重叠：每次 tryBegin 成功都会 ++runningCount。
 * end() 按次数释放；Job 已被 DELETE 从 Registry 移除时传入 null，静默忽略。
 *
 * 本守卫是进程内协作式互斥，不是分布式锁。
 *
 * @see CronManager::onTrigger()
 * @see CronManager::runOnceNow()
 */
final class ExecutionGuard
{
    /**
     * 尝试获得本轮执行权。
     *
     * @return bool false 表示应 SKIP（不允许重叠且已有实例在跑）
     */
    public function tryBegin(RuntimeJob $job): bool
    {
        // 临界区开始：检查 + 标记之间禁止任何会让出协程的 I/O / sleep
        if ($job->definition->withBlockLapping && $job->running) {
            return false;
        }
        $job->running = true;
        ++$job->runningCount;
        // 临界区结束

        return true;
    }

    /**
     * 释放本轮执行权。Job 已被 DELETE 时静默忽略。
     */
    public function end(?RuntimeJob $job): void
    {
        if ($job === null) {
            return;
        }
        $job->runningCount = max(0, $job->runningCount - 1);
        $job->running = $job->runningCount > 0;
    }
}
