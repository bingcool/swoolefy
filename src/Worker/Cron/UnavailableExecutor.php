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
 * 每次执行都直接失败的执行器。
 *
 * 用于「Worker 起得来但执行能力不可用」的场景，典型是 Kubernetes 凭证缺失。
 *
 * 为什么不直接让 Worker 启动失败：Worker 抛异常会走 reboot，形成
 * 「起不来 → reboot → 起不来」的循环，日志里只有栈没有原因。改成每条 Execution
 * 都写一条带原因的 FAILED，调度语义（Slot / Timer / 指标）保持完整，运维在
 * Admin 的执行记录里就能看到到底缺了什么。
 */
final class UnavailableExecutor implements CronExecutorInterface
{
    public function __construct(private readonly string $reason)
    {
    }

    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        return ExecutionResult::failed($this->reason);
    }
}
