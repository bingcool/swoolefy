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
 * 调度计划：只负责根据基准时间计算下一次计划执行点。
 *
 * CronScheduler 不应再解析表达式字符串，统一消费 calculateNextRunAt()。
 * 实现分 IntervalSchedule（秒级网格）与 CronExpressionSchedule（Linux Cron）。
 * 本接口不持有 Timer，也不执行任务。
 *
 * @see IntervalSchedule
 * @see CronExpressionSchedule
 * @see ExpressionParser
 */
interface ScheduleInterface
{
    /**
     * 计算严格晚于 $fromTimestamp 的下一次计划时间（unix 秒）。
     *
     * 不回填历史 misfire：调用方应传入“当前时刻”或“刚刚触发的计划点”。
     *
     * @param int $fromTimestamp 基准时间（unix 秒）
     * @return int 下一次计划 unix 秒
     */
    public function calculateNextRunAt(int $fromTimestamp): int;

    /**
     * 返回规范化后的表达式原文，便于诊断。
     */
    public function expression(): string;
}
