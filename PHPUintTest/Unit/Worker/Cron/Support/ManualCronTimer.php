<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron\Support;

use Swoolefy\Worker\Cron\CronTimerInterface;

/**
 * 可手动推进的 Timer，不依赖真实 Swoole 时钟。
 *
 * 实现 CronTimerInterface：after 为 one-shot，tick 为周期（Polling）。
 * advance($ms) 推进虚拟毫秒并 fireDue()；one-shot 触发后移除，tick 改写 due。
 * fireDue 有 100 次护栏，避免回调里再次 arm 造成死循环。
 *
 * @see \Swoolefy\Worker\Cron\CronTimerInterface
 */
final class ManualCronTimer implements CronTimerInterface
{
    private int $seq = 0;

    private int $nowMs = 0;

    /** @var array<int, array{due:int,fn:callable,tick:?int}> */
    private array $timers = [];

    /**
     * 一次性延迟。due = 当前虚拟毫秒 + max(1, ms)。
     */
    public function after(int $ms, callable $fn): int
    {
        $id = ++$this->seq;
        $this->timers[$id] = ['due' => $this->nowMs + max(1, $ms), 'fn' => $fn, 'tick' => null];

        return $id;
    }

    /**
     * 周期 tick（仅测 Config Polling）。触发后 due += interval。
     */
    public function tick(int $ms, callable $fn): int
    {
        $id = ++$this->seq;
        $this->timers[$id] = ['due' => $this->nowMs + max(1, $ms), 'fn' => $fn, 'tick' => max(1, $ms)];

        return $id;
    }

    public function clear(int $timerId): bool
    {
        $exists = isset($this->timers[$timerId]);
        unset($this->timers[$timerId]);

        return $exists;
    }

    public function exists(int $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    public function clearAll(): void
    {
        $this->timers = [];
    }

    /**
     * 推进虚拟毫秒并触发到期回调（one-shot 触发后移除）。
     */
    public function advance(int $ms): void
    {
        $this->nowMs += $ms;
        $this->fireDue();
    }

    /**
     * 触发所有 due <= nowMs 的回调。one-shot 先 unset 再调用，避免重入看到旧 Timer。
     */
    public function fireDue(): void
    {
        $guard = 0;
        while ($guard++ < 100) {
            $dueIds = [];
            foreach ($this->timers as $id => $timer) {
                if ($timer['due'] <= $this->nowMs) {
                    $dueIds[] = $id;
                }
            }
            if ($dueIds === []) {
                return;
            }
            foreach ($dueIds as $id) {
                if (!isset($this->timers[$id])) {
                    continue;
                }
                $timer = $this->timers[$id];
                if ($timer['tick'] === null) {
                    unset($this->timers[$id]);
                } else {
                    $this->timers[$id]['due'] = $this->nowMs + $timer['tick'];
                }
                ($timer['fn'])();
            }
        }
    }

    /**
     * 当前仍存活的 timerId 列表，供堆积断言。
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_keys($this->timers);
    }

    /**
     * 存活 Timer 数量（含 Polling tick 与 Job one-shot）。
     */
    public function count(): int
    {
        return count($this->timers);
    }
}
