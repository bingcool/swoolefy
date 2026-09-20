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
 * RunOnce Claim 闸门契约。
 *
 * 与 {@see CronScheduleSlotClaimConst} 对称，但多了 ack / defer：
 * UNIQUE(request_id) 冲突时要区分「终态只确认」和「别人正在跑」。
 *
 * 调用链：
 * - {@see CronManager::runOnceNow()} 以 `runOnceNow` 进入管线
 * - 时间窗 / 重叠守卫之后、`writeLog(RUNNING)` 之前调用 `run_once_claim(requestId)`
 * - 真正的原子边界仍是 RUNNING INSERT + UNIQUE(request_id)
 * - 1062 时抛 {@see CronRunOnceAlreadyClaimedException}
 *
 * @see CronManager::runExecutionPipeline()
 */
final class CronRunOnceClaimConst
{
    /** 本实例可以继续 INSERT Execution 并执行。未配置回调时也视为 CREATED。 */
    public const CREATED = 'created';

    /** 已有终态 Execution：返回 SUCCESS 以便 consume 补 ack，禁止再执行。 */
    public const ACK = 'ack';

    /** 已有 RUNNING 且租约有效（或 Recovery CAS 失败）：SKIPPED，不 ack。 */
    public const DEFER = 'defer';

    /** Claim 过程失败（DB / 回调异常）。返回 FAILED，不执行。 */
    public const FAILED = 'failed';
}
