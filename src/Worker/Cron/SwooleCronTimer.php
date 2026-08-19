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

use Swoolefy\Core\Timer\Tick;

/**
 * 生产 Timer：封装 Tick（afterTimer / tickTimer / delTicker），并跟踪本 Cron 实例创建的 timerId。
 *
 * 边界：只做 after / tick / clear / exists。不计算 nextRunAt，不执行任务。
 * Job 调度只用 after（one-shot）；tick 仅给 CronManager Config Polling 与节点心跳。
 *
 * $owned 记录本实例创建的 timerId，Worker Stop 时 clearAll() 只清自己的，
 * 避免误清其它业务 Timer。必须显式 clearAll()，不能只依赖对象析构
 *（Swoole Timer 活在进程里，PHP 对象释放不会自动 clear）。
 *
 * 协程不变量（Swoole 6）：
 * Tick::afterTimer / tickTimer 已在回调里 goApp，并写入 x-trace-id 等系统字段。
 * 本类不再二次 goApp，避免双开协程。proc_open / curl / 阻塞 IO 必须进协程，
 * 否则 Fatal: API must be called in the coroutine。
 * 不在事件循环里同步等待执行结束（Channel::pop 会死锁）。
 * Guard / 日志 / 结果仍在该协程内同步完成，finally 释放 running。
 *
 * CronLocalProcess 仍用 CrontabManager，协程模型相同，但调度器互不共用。
 *
 * @see CronTimerInterface
 * @see CronScheduler
 * @see CronManager
 * @see Tick
 */
final class SwooleCronTimer implements CronTimerInterface
{
    /** @var array<int, true> 本实例创建、尚未 clear 的 timerId */
    private array $owned = [];

    /**
     * 一次性延迟。ms < 1 提升为 1，避免 Tick / Swoole 立即触发或拒绝。
     * 协程与 x-trace-id 由 Tick::afterTimer 负责，此处不再二次 goApp。
     */
    public function after(int $ms, callable $fn): int
    {
        $timerId = (int) Tick::afterTimer(max(1, $ms), static function () use ($fn): void {
            $fn();
        });
        $this->owned[$timerId] = true;

        return $timerId;
    }

    /**
     * 周期 tick，用于 Config Polling 与节点心跳，禁止用于 Job 调度（Job 必须 one-shot）。
     * 协程与 x-trace-id 由 Tick::tickTimer 负责，便于 fetcher 走 DB / Redis。
     */
    public function tick(int $ms, callable $fn): int
    {
        $timerId = (int) Tick::tickTimer(max(1, $ms), static function () use ($fn): void {
            $fn();
        });
        $this->owned[$timerId] = true;

        return $timerId;
    }

    /**
     * 从 $owned 移除并 clear。timer 已不存在时返回 false，不抛异常。
     */
    public function clear(int $timerId): bool
    {
        unset($this->owned[$timerId]);
        if ($timerId > 0 && \Swoole\Timer::exists($timerId)) {
            return Tick::delTicker($timerId);
        }

        return false;
    }

    /**
     * 供 CronScheduler::activeTimerCount() 断言 Active Job = 0 或 1 个有效 Timer。
     * Tick 不封装 exists，仍查 Swoole Timer 是否存活。
     */
    public function exists(int $timerId): bool
    {
        return $timerId > 0 && \Swoole\Timer::exists($timerId);
    }

    /**
     * Worker Stop / Restart：清掉本实例全部 Timer，并清空 $owned。
     */
    public function clearAll(): void
    {
        foreach (array_keys($this->owned) as $timerId) {
            if (\Swoole\Timer::exists($timerId)) {
                Tick::delTicker($timerId);
            }
        }
        $this->owned = [];
    }
}
