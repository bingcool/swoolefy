<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 单调递增的 Worker 本地指标。
 *
 * Counter 不支持递减，避免错误观测导致累计值产生歧义。
 */
final class Counter
{
    private int $value = 0;

    /**
     * 按非负整数增加计数器。
     *
     * @throws \InvalidArgumentException 增量为负数时抛出。
     */
    public function increment(int $value = 1): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Counter increment must be >= 0.');
        }

        $this->value += $value;
    }

    /** 返回当前 Worker 本地计数。 */
    public function value(): int
    {
        return $this->value;
    }
}
