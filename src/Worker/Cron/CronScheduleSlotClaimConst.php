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
 * 调度 Slot 抢占：管线 source 与 claim 回调的契约常量。
 *
 * 解决的问题：同一 `cron_id` + 同一调度点（plannedAt）被两套 Scheduler 同时 fire
 *（同 `CRON_NODE_ID` 双机、`worker_num > 1`、进程 reboot 重叠）。目标是
 * **同一 Slot 最多一次 HTTP / proc_open / Execution 日志**。
 *
 * 不解决跨 Slot 重叠（那是 `with_block_lapping` / {@see ExecutionGuard}），
 * 不引入 Leader / Redis Lock。时钟偏差靠 {@see SKEW_SECONDS} 窗口吸收。
 *
 * 调用链：
 * - {@see CronManager::onTrigger()} 以 {@see TRIGGER} 进入管线
 * - Guard 成功后、`writeLog(RUNNING)` 之前调用 `schedule_slot_claim(cronTaskId, plannedAt)`
 * - 回调必须返回 {@see CREATED} / {@see DUPLICATE} / {@see FAILED} 之一
 * - {@see CronManager::runOnceNow()} 不走本闸门（手工执行允许与调度并行）
 *
 * CronManager 与业务侧 claim 实现（如 UNIQUE(cron_id, scheduled_at) 表）共用本类，
 * 禁止再写 `'trigger'` / `'created'` / `'duplicate'` / `'failed'` 字面量。
 *
 * 不要与下列常量混淆：
 * - {@see ExecutionStatus::FAILED} 是 cron_task_log.status 整型 3
 * - {@see ExecutionResult::FAILED} 是执行结果字符串 `'FAILED'`
 * - 本类取值全是小写，只描述 Slot 抢占结果，不是 Execution 终态
 *
 * @see CronManager::runExecutionPipeline()
 */
final class CronScheduleSlotClaimConst
{
    /**
     * 调度器按 expression / nextRunAt 触发的管线 source。
     *
     * 仅此 source 才抢 Slot。{@see CronManager::runOnceNow()} 使用 `'runOnceNow'`，
     * 故意不 claim，避免手工执行被调度点挡住。
     */
    public const TRIGGER = 'trigger';

    /**
     * 本实例抢到该 Slot：允许继续 writeLog(RUNNING) 并真正执行。
     *
     * 未配置 `schedule_slot_claim` 回调、或任务没有 `cron_task_id` 时，
     * CronManager 也视为 CREATED（静态 conf / 无 DB 场景放行）。
     */
    public const CREATED = 'created';

    /**
     * 其他 Agent / Worker 已占该调度点：本实例是输家。
     *
     * CronManager 必须直接 {@see ExecutionResult::skipped()} 返回，
     * **禁止** `recordSkip()` / `writeLog()`，输家不得留下 Execution 行。
     * 这是预期竞争，不是异常。
     */
    public const DUPLICATE = 'duplicate';

    /**
     * 抢占过程失败：DB 故障、任务已删、回调抛异常、或返回了无法识别的值。
     *
     * CronManager 返回 {@see ExecutionResult::failed()}，同样不写 RUNNING 日志、
     * 不执行 HTTP / Shell。不得把 FAILED 当成 DUPLICATE 吞掉，便于暴露基础设施问题。
     */
    public const FAILED = 'failed';

    /**
     * plannedAt 前后容差（秒），用于把时钟偏差收成同一个 Slot。
     *
     * 窗口 `[plannedAt - SKEW, plannedAt + SKEW]` 内已有 Record 即视为 DUPLICATE。
     * 取 2 是因为 Interval 下限 5 秒：`2 + 2 = 4 < 5`，不会把下一格吃掉。
     */
    public const SKEW_SECONDS = 2;
}
