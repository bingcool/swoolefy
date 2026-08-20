<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron\Support;

use Swoolefy\Worker\Cron\CronTimerInterface;

/**
 * 包装 ManualCronTimer：可在 after() 抛异常，模拟 Timer 分配失败。
 */
final class ThrowingAfterCronTimer implements CronTimerInterface
{
    public bool $throwOnAfter = false;

    public function __construct(private readonly ManualCronTimer $inner)
    {
    }

    public function after(int $ms, callable $fn): int
    {
        if ($this->throwOnAfter) {
            throw new \RuntimeException('timer alloc failed');
        }

        return $this->inner->after($ms, $fn);
    }

    public function tick(int $ms, callable $fn): int
    {
        return $this->inner->tick($ms, $fn);
    }

    public function clear(int $timerId): bool
    {
        return $this->inner->clear($timerId);
    }

    public function exists(int $timerId): bool
    {
        return $this->inner->exists($timerId);
    }

    public function clearAll(): void
    {
        $this->inner->clearAll();
    }

    public function advance(int $ms): void
    {
        $this->inner->advance($ms);
    }

    public function count(): int
    {
        return $this->inner->count();
    }
}
