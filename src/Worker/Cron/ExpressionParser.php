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
use Swoolefy\Exception\CronException;

/**
 * 统一解析 Cron expression：纯数字 → 秒级 IntervalSchedule，否则 → CronExpressionSchedule。
 *
 * 本类是纯解析器：不访问 DB、不武装 Timer、不执行任务。
 * CronScheduler 只消费 ScheduleInterface，不再自己做字符串判断。
 *
 * 判定规则：
 * - int / float / ctype_digit 字符串 → Interval，必须 >= 1
 * - 其余 trim 后交给 dragonmantank/cron-expression 校验五段 Linux Cron
 * - 空串、0、负数、非法 Cron 一律抛 CronException，由 CronManager::applyOp() 隔离
 *
 * 时区只作用于 Linux Cron；秒级 Interval 对齐 unix 网格，与时区无关。
 *
 * @see IntervalSchedule
 * @see CronExpressionSchedule
 * @see CronManager::applyAdd()
 */
final class ExpressionParser
{
    /**
     * 将表达式解析为可计算 nextRunAt 的 Schedule。
     *
     * @param int|float|string $expression 纯数字秒级间隔，或 Linux Cron 五段表达式
     * @param string|null $timezone 仅 Linux Cron 使用；空则复用 PHP 默认时区
     * @throws CronException 表达式非法时
     */
    public function parse(int|float|string $expression, ?string $timezone = null): ScheduleInterface
    {
        if (is_int($expression) || is_float($expression) || $this->isSecondInterval((string) $expression)) {
            $seconds = (int) $expression;
            if ($seconds < 1) {
                throw new CronException('秒级 Interval 表达式必须是 >= 1 的整数');
            }

            return new IntervalSchedule($seconds);
        }

        $cronExpression = trim((string) $expression);
        if ($cronExpression === '' || !CronExpression::isValidExpression($cronExpression)) {
            throw new CronException(sprintf('非法的 Cron 表达式: %s', $cronExpression));
        }

        return new CronExpressionSchedule($cronExpression, $timezone);
    }

    /**
     * 是否为秒级 Interval（纯数字，不含 Cron 空格字段）。
     */
    public function isSecondInterval(string $expression): bool
    {
        $expression = trim($expression);

        return $expression !== '' && ctype_digit($expression);
    }
}
