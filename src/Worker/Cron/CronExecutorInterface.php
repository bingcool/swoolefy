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
 * 执行器：只负责“怎么执行”，不负责调度。
 *
 * 边界：
 * - 只消费 ExecutionSnapshot 冻结定义，不回读 RuntimeJobRegistry
 * - 不武装 / 清除 Timer（那是 CronScheduler 的职责）
 * - 不判断 cron_between / cron_skip / with_block_lapping（Window / Guard 已处理）
 * - 单个 Job 失败必须返回 ExecutionResult::failed()，不得上抛到 Worker（P0-5）
 *
 * SKIPPED 由 CronManager 管线产生，Executor 只返回 SUCCESS / FAILED。
 *
 * @see ShellExecutor
 * @see HttpExecutor
 * @see CompositeExecutor
 */
interface CronExecutorInterface
{
    /**
     * 执行本轮冻结 Snapshot，返回成功或失败。禁止抛出到 CronManager 之外。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult;
}
