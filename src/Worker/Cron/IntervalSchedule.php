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
 * 秒级 Interval 计划。
 *
 * 纯数字 expression（如 15）表示每隔 N 秒。下一次执行对齐到 unix 时间网格，
 * 而不是 finish_time + N，从而避免任务耗时造成的漂移。
 *
 * 例如 interval=15：…/00 /15 /30 /45，即使某次执行耗时 5 秒，下一轮仍落在网格上。
 * CronManager::onTrigger() 用“刚刚触发的计划点”作为 from，而不是 finish 墙钟。
 *
 * Enable ≠ Immediately Run：from 恰好落在网格点上则跳过当前点，等待下一格。
 *
 * @see ExpressionParser
 * @see CronExpressionSchedule
 */
final class IntervalSchedule implements ScheduleInterface
{
    /**
     * @param int $seconds 间隔秒数，必须 >= 5
     */
    public function __construct(private readonly int $seconds)
    {
        if ($this->seconds < 5) {
            throw new \InvalidArgumentException('秒级 Interval 必须 >= 5');
        }
    }

    /**
     * {@inheritDoc}
     *
     * 对齐规则：next = floor(from / interval) * interval + interval。
     * 若 from 恰好落在网格点上（Enable 或刚触发），则跳过当前点，等待下一格。
     * Enable ≠ Immediately Run。
     */
    public function calculateNextRunAt(int $fromTimestamp): int
    {
        $aligned = intdiv($fromTimestamp, $this->seconds) * $this->seconds;
        $next = $aligned + $this->seconds;
        if ($next <= $fromTimestamp) {
            $next += $this->seconds;
        }

        return $next;
    }

    public function expression(): string
    {
        return (string) $this->seconds;
    }

    /**
     * 间隔秒数。
     */
    public function seconds(): int
    {
        return $this->seconds;
    }
}
