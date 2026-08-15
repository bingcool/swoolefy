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

use Cron\CronExpression;

/**
 * Linux Cron 分钟级计划（五段表达式）。
 *
 * 复用 dragonmantank/cron-expression。时区沿用任务 timezone 或
 * date_default_timezone_get()，不另建第二套 Timezone 配置。
 *
 * 只计算严格晚于 $fromTimestamp 的下一触发点，不补偿 Worker 停机期间的历史 misfire。
 * Enable ≠ Immediately Run：若 from 恰好落在 Cron 点上，仍取“下一个”点。
 *
 * @see ExpressionParser
 * @see IntervalSchedule
 */
final class CronExpressionSchedule implements ScheduleInterface
{
    private readonly CronExpression $cron;

    /**
     * @param string $expression 标准 5 段 Linux Cron 表达式
     * @param string|null $timezone 为空则使用 date_default_timezone_get()
     */
    public function __construct(
        private readonly string $expression,
        private readonly ?string $timezone = null,
    ) {
        $this->cron = new CronExpression($this->expression);
    }

    /**
     * {@inheritDoc}
     *
     * 取严格晚于 $fromTimestamp 的下一次 Cron 触发点，不补偿 Worker 停机期间的历史点。
     */
    public function calculateNextRunAt(int $fromTimestamp): int
    {
        $tzName = $this->timezone ?: date_default_timezone_get();
        $tz = new \DateTimeZone($tzName);
        $from = (new \DateTimeImmutable('@' . $fromTimestamp))->setTimezone($tz);
        $next = $this->cron->getNextRunDate($from, 0, false, $tzName);

        return $next->getTimestamp();
    }

    /**
     * 获取表达式
     */
    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * 本计划使用的时区名。
     */
    public function timezone(): string
    {
        return $this->timezone ?: date_default_timezone_get();
    }
}
