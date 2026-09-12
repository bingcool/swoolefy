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

declare(strict_types=1);

namespace Swoolefy\Worker\Cron;

/**
 * 不落库、不响应取消的空实现。
 *
 * 用于单测与「没有 cron_task_log 的纯框架用法」。生产环境（schedule-job Agent）
 * 必须注入真实实现，否则取消请求删不掉 Job（方案 §11.1 的孤儿 Job 问题）。
 */
final class NullKubernetesExecutionHook implements KubernetesExecutionHookInterface
{
    public function onJobPlanned(ExecutionSnapshot $snapshot, string $namespace, string $jobName): int
    {
        return 0;
    }

    public function onJobCreated(
        ExecutionSnapshot $snapshot,
        string $namespace,
        string $jobName,
        string $uid,
        callable $terminator,
    ): void {
    }

    /**
     * 永远继续等待：只有 Executor 自己的硬上限（timeout / K8S_MAX_WAIT_SECONDS）能结束循环。
     */
    public function stopSignal(ExecutionSnapshot $snapshot): string
    {
        return self::STOP_NONE;
    }

    public function onJobFinished(ExecutionSnapshot $snapshot, array $meta): void
    {
    }
}
