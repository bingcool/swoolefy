<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 可变的 Worker 本地数值指标，适用于当前值。
 */
final class Gauge
{
    private int|float $value = 0;

    /** 设置当前值。 */
    public function set(int|float $value): void
    {
        $this->value = $value;
    }

    /** 增加 Gauge 值。 */
    public function increment(int|float $value = 1): void
    {
        $this->value += $value;
    }

    /** 减少 Gauge 值。 */
    public function decrement(int|float $value = 1): void
    {
        $this->value -= $value;
    }

    /** 返回当前值。 */
    public function value(): int|float
    {
        return $this->value;
    }
}
