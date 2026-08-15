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
 * Cron 定时器抽象。
 *
 * 生产走 SwooleCronTimer，单测注入 ManualCronTimer，避免依赖真实 Swoole 时钟。
 * Job 调度只用 after（one-shot）；tick 仅给 Config Polling。
 * Worker Stop / Restart 必须 clearAll()，禁止只依赖析构。
 *
 * 生产实现必须把 after/tick 回调投进协程（Swoole 6 proc_open/HTTP 要求）。
 * 单测实现可同步触发，便于断言 Guard / Snapshot。
 *
 * @see SwooleCronTimer
 * @see CronScheduler
 */
interface CronTimerInterface
{
    /**
     * 一次性延迟回调。
     *
     * @param int $ms 延迟毫秒
     * @param callable():void $fn
     * @return int timerId
     */
    public function after(int $ms, callable $fn): int;

    /**
     * 周期回调，仅用于 Config Polling，不用于 Job 调度。
     *
     * @param int $ms
     * @param callable():void $fn
     * @return int timerId
     */
    public function tick(int $ms, callable $fn): int;

    /**
     * 取消指定 timer。已不存在时实现应返回 false，不要抛异常。
     */
    public function clear(int $timerId): bool;

    /**
     * timer 是否仍有效。供 Active Job = 恰好一个 Timer 的不变量断言。
     */
    public function exists(int $timerId): bool;

    /**
     * 显式清理全部 Timer。Worker Stop / Restart 必须调用。
     */
    public function clearAll(): void;
}
