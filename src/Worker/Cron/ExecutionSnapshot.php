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
 * 本轮 Execution 冻结的任务定义。
 *
 * 在 Guard 拿到执行权之后创建，持有当时的 TaskDefinition 引用。
 * 之后 ConfigDiff::UPDATE 只会替换 RuntimeJob::$definition，
 * 不得改写当前 Snapshot。本轮 command / url / headers 保持冻结，
 * 下一轮 onTrigger 才会使用新定义（Snapshot 边界）。
 *
 * execBatchId 每轮随机，供 cron_task_log 把同一轮开始/结束/PID 串起来。
 * plannedAt 是本轮计划点（nextRunAt），不是实际 start 墙钟。
 *
 * attempt 是「本批次内第几次尝试」，从 1 开始，由 CronManager::runWithRetry() 在每次
 * 调用 Executor 前通过 withAttempt() 派生。重试不换 execBatchId，也不新开 cron_task_log
 * 行，因此 Executor 若需要一个「每次尝试都唯一」的外部资源名（例如 Kubernetes Job 名，
 * 同名 Job 已存在会 409），必须用 execBatchId + attempt 组合，单靠 execBatchId 不够。
 *
 * @see CronManager::onTrigger()
 * @see CronManager::runOnceNow()
 * @see CronManager::runWithRetry()
 * @see CronExecutorInterface::run()
 */
final class ExecutionSnapshot
{
    /**
     * @param int $attempt 本批次内第几次尝试，从 1 开始；仅由 runWithRetry() 派生
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $execBatchId,
        public readonly TaskDefinition $definition,
        public readonly int $plannedAt,
        public readonly int $attempt = 1,
    ) {
    }

    /**
     * 从当前 RuntimeJob 生成新批次。definition 按引用冻结，不是 clone。
     * TaskDefinition 本身不可变，UPDATE 是换对象而不是改字段。
     */
    public static function create(RuntimeJob $job, int $plannedAt): self
    {
        return new self(
            jobId: $job->jobId,
            execBatchId: bin2hex(random_bytes(8)),
            definition: $job->definition,
            plannedAt: $plannedAt,
        );
    }

    /**
     * 派生同批次的第 N 次尝试。
     *
     * 除 attempt 外所有字段（尤其是 execBatchId 与冻结的 definition）保持不变，
     * 因此 cron_task_log 仍然是同一行、同一 exec_batch_id，只有 Executor 侧的
     * 「每次尝试唯一资源名」会随之变化。attempt < 1 一律纠正为 1。
     */
    public function withAttempt(int $attempt): self
    {
        $attempt = max(1, $attempt);
        if ($attempt === $this->attempt) {
            return $this;
        }

        return new self(
            jobId: $this->jobId,
            execBatchId: $this->execBatchId,
            definition: $this->definition,
            plannedAt: $this->plannedAt,
            attempt: $attempt,
        );
    }
}
