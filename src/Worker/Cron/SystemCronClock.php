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
 * 生产时钟：直接读取系统 unix 秒，与 PHP/OS 默认时区保持一致。
 *
 * 不另建第二套 Timezone 配置。Linux Cron 的时区由 CronExpressionSchedule
 * 使用任务 timezone 或 date_default_timezone_get()。
 * 单测应注入 FrozenCronClock，不要 mock 本类。
 *
 * @see CronClockInterface
 */
final class SystemCronClock implements CronClockInterface
{
    /**
     * 当前 unix 秒（time()）。
     */
    public function now(): int
    {
        return time();
    }
}
