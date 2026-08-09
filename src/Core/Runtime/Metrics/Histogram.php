<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 使用固定内存汇总观测值。
 *
 * 不保存原始观测值或百分位数，确保长驻 Worker 中的请求埋点内存有界。
 */
final class Histogram
{
    private int $count = 0;
    private float $sum = 0.0;
    private float $min = 0.0;
    private float $max = 0.0;

    /** 记录一个非负观测值。 */
    public function observe(float $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Histogram value must be >= 0.');
        }

        if ($this->count === 0) {
            $this->min = $value;
            $this->max = $value;
        } else {
            $this->min = min($this->min, $value);
            $this->max = max($this->max, $value);
        }
        ++$this->count;
        $this->sum += $value;
    }

    /**
     * 返回仅含标量值的汇总，可安全用于诊断或导出适配器。
     *
     * @return array{count:int,sum:float,min:float,max:float,avg:float}
     */
    public function snapshot(): array
    {
        return [
            'count' => $this->count,
            'sum' => $this->sum,
            'min' => $this->min,
            'max' => $this->max,
            'avg' => $this->count > 0 ? $this->sum / $this->count : 0.0,
        ];
    }
}
