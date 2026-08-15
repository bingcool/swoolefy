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
 * 统一时间基准。
 *
 * CronScheduler / TimeWindowFilter / CronManager 都通过本接口取 now，
 * 禁止直接 time()，以便单测用 FrozenCronClock 对齐 nextRunAt。
 * 生产使用 SystemCronClock。
 *
 * @see SystemCronClock
 * @see CronScheduler::arm()
 */
interface CronClockInterface
{
    /**
     * 当前 unix 秒。不得返回毫秒。
     */
    public function now(): int;
}
