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
 * @see CronManager::onTrigger()
 * @see CronExecutorInterface::run()
 */
final class ExecutionSnapshot
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $execBatchId,
        public readonly TaskDefinition $definition,
        public readonly int $plannedAt,
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
}
