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
 * 进程内 Runtime Job 注册表。
 *
 * 仅保存当前 Worker 已知的任务状态，不引入 Redis / 分布式锁 / 其它节点视图。
 * 键为 TaskDefinition::jobId。ConfigDiff 通过 definitions() 取不可变快照做比较，
 * 本类不负责 Timer、不负责执行、不负责 Diff。
 *
 * 生命周期：
 * - ADD：put() 写入
 * - DELETE 且未 running：remove()
 * - DELETE 且 running：Job 仍留在表中，等 Execution finally 再 remove
 * - Worker Stop：clear() 显式释放，不能只依赖析构
 *
 * Last Known Good：DB 故障时 CronManager 不会 clear()，表内状态原样保留。
 *
 * @see ConfigDiff
 * @see CronManager::applyRows()
 */
final class RuntimeJobRegistry
{
    /** @var array<string, RuntimeJob> */
    private array $jobs = [];

    /**
     * 按 jobId 写入或覆盖。UPDATE 不走本方法，而是改已有 RuntimeJob 的字段。
     */
    public function put(RuntimeJob $job): void
    {
        $this->jobs[$job->jobId] = $job;
    }

    /**
     * 按 jobId 取 Job；不存在返回 null（触发时可能已被 DELETE 移除）。
     */
    public function get(string $jobId): ?RuntimeJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function has(string $jobId): bool
    {
        return isset($this->jobs[$jobId]);
    }

    /**
     * 从注册表移除。调用方须先 CronScheduler::clear()，避免残留 Timer。
     */
    public function remove(string $jobId): void
    {
        unset($this->jobs[$jobId]);
    }

    /**
     * 当前全部 Job（含 Disabled、以及 DELETE 后仍 running 的）。
     *
     * @return array<string, RuntimeJob>
     */
    public function all(): array
    {
        return $this->jobs;
    }

    /**
     * 当前 Runtime 中的定义快照，供 Config Diff 使用。
     *
     * @return array<string, TaskDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->jobs as $jobId => $job) {
            $definitions[$jobId] = $job->definition;
        }

        return $definitions;
    }

    /**
     * Registry 内 Job 总数（含 Disabled / 待释放的 deleted）。
     */
    public function count(): int
    {
        return count($this->jobs);
    }

    /**
     * 仍应持有 Schedule Timer 的 Job 数（enabled 且未 deleted）。
     */
    public function enabledCount(): int
    {
        $n = 0;
        foreach ($this->jobs as $job) {
            if ($job->isSchedulable()) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * 至少有一个 Execution 在跑的 Job 数（不是 runningCount 之和）。
     */
    public function runningCount(): int
    {
        $n = 0;
        foreach ($this->jobs as $job) {
            if ($job->running) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * 显式释放全部 Job 引用。Worker Stop 必须调用，不能只依赖析构。
     */
    public function clear(): void
    {
        $this->jobs = [];
    }
}
