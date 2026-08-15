<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron\Support;

use Swoolefy\Worker\Cron\CronClockInterface;

/**
 * 可推进的冻结时钟，供 Cron 单测对齐 nextRunAt。
 *
 * 实现 CronClockInterface，替换 SystemCronClock。
 * set() 跳到绝对 unix 秒；advance() 相对前进。不自动与 ManualCronTimer 同步，
 * 测试需同时推进 clock 与 timer（毫秒）。
 *
 * @see \Swoolefy\Worker\Cron\CronClockInterface
 */
final class FrozenCronClock implements CronClockInterface
{
    public function __construct(private int $now)
    {
    }

    /**
     * 当前冻结的 unix 秒。
     */
    public function now(): int
    {
        return $this->now;
    }

    /**
     * 跳到指定 unix 秒（例如对齐到计划点再 fire Timer）。
     */
    public function set(int $now): void
    {
        $this->now = $now;
    }

    /**
     * 相对前进若干秒。
     */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
