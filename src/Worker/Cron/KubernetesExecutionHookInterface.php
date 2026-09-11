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
 * {@see KubernetesExecutor} 与「持久化 / 取消」这一侧的唯一接缝。
 *
 * ## 为什么需要它
 *
 * Executor 只拿得到 {@see ExecutionSnapshot}，里面**没有** `cron_task_log.id`。
 * 但方案有三件事必须跨到应用层：
 *
 * 1. **Create 之前**把 Job 名落库（§12）：否则「Create 成功后立刻崩溃」会彻底丢失句柄，
 *    只能靠 `exec_batch_id` 反推，那是兜底不是主路径。
 * 2. **取消 / 超时**要能真的删掉 Job（§11.1）：`ExecutionRuntimeGuard` 在 pid=0 时
 *    直接把 Execution 标成 CANCELLED 就收工，Kubernetes 里会留下孤儿 Job 继续跑。
 *    Executor 必须把「删 Job」这个句柄交给 Guard。
 * 3. Executor 自己也要能**察觉**外部状态变化，否则会一直等一个已经被判死的 Job。
 *
 * 框架层不认识 `cron_task_log`，所以这些动作由应用层（schedule-job Agent）实现本接口注入。
 * 不注入时用 {@see NullKubernetesExecutionHook}，Executor 退化成「只管建 Job 和等结果」，
 * 单测与无 DB 场景可用。
 *
 * 实现约定：**任何方法都不应抛异常**。持久化失败不能改变一次 Kubernetes 执行的结论；
 * Executor 会捕获并降级，但实现方自己吞掉更清晰。
 */
interface KubernetesExecutionHookInterface
{
    /** {@see stopSignal} 返回值：Admin 侧请求了取消。 */
    public const STOP_CANCELLED = 'CANCELLED';

    /** {@see stopSignal} 返回值：已越过 `timeout_at`，或 Guard 已判超时。 */
    public const STOP_TIMEOUT = 'TIMEOUT';

    /** {@see stopSignal} 返回值：继续等待。 */
    public const STOP_NONE = '';

    /**
     * Create Job **之前**调用：把即将使用的 Job 名落库。
     *
     * @return int 对应的 `cron_task_log.id`；查不到返回 0（Job 名不依赖它，§9.1）
     */
    public function onJobPlanned(ExecutionSnapshot $snapshot, string $namespace, string $jobName): int;

    /**
     * Job 已在集群中存在（新建成功，或 409 命中自己创建的同名 Job）时调用。
     *
     * @param string   $uid        Job 的 metadata.uid，用于区分同名但被重建过的对象
     * @param callable $terminator `fn(): bool`，调用即以 Background 策略删除该 Job。
     *                             实现方应把它交给 Guard，让取消路径先删 Job 再收尾。
     */
    public function onJobCreated(
        ExecutionSnapshot $snapshot,
        string $namespace,
        string $jobName,
        string $uid,
        callable $terminator,
    ): void;

    /**
     * 轮询期间询问「是否该停下」。
     *
     * 典型实现：读 `cron_task_log` 当前行，status = CANCEL_REQUESTED / 已是终态 CANCELLED
     * → {@see STOP_CANCELLED}；已越过 `timeout_at` → {@see STOP_TIMEOUT}。
     *
     * @return string {@see STOP_NONE} / {@see STOP_CANCELLED} / {@see STOP_TIMEOUT}
     */
    public function stopSignal(ExecutionSnapshot $snapshot): string;

    /**
     * 本次 attempt 结束（无论成败）时调用：写回元数据并解除 Guard 上的删 Job 句柄。
     *
     * 必须解除：否则重试进入 attempt 2 后，Guard 手里还攥着 attempt 1 的 Job 名。
     *
     * @param array<string, mixed> $meta k8s_namespace / k8s_job_name / k8s_job_uid /
     *                                   k8s_pod_name / k8s_attempt / k8s_image
     */
    public function onJobFinished(ExecutionSnapshot $snapshot, array $meta): void;
}
