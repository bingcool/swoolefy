<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 管理单个 Worker 进程中由框架定义且数量有界的指标。
 *
 * 此注册表不包含标签或导出器：调用方仅使用固定的框架名称，
 * 以避免无界的基数增长和请求路径 I/O。
 */
final class MetricsRegistry
{
    /** @var array<string, Counter> */
    private array $counters = [];
    /** @var array<string, Gauge> */
    private array $gauges = [];
    /** @var array<string, Histogram> */
    private array $histograms = [];

    /** 按固定指标名返回（或创建）Counter。 */
    public function counter(string $name): Counter
    {
        return $this->counters[$name] ??= new Counter();
    }

    /** 按固定指标名返回（或创建）Gauge。 */
    public function gauge(string $name): Gauge
    {
        return $this->gauges[$name] ??= new Gauge();
    }

    /** 返回（或创建）使用固定内存的 Histogram。 */
    public function histogram(string $name): Histogram
    {
        return $this->histograms[$name] ??= new Histogram();
    }

    /**
     * 复制当前指标值，不暴露可变的指标对象。
     *
     * @return array{counter:array<string,int>,gauge:array<string,int|float>,histogram:array<string,array>}
     */
    public function snapshot(): array
    {
        $result = ['counter' => [], 'gauge' => [], 'histogram' => []];
        foreach ($this->counters as $name => $metric) {
            $result['counter'][$name] = $metric->value();
        }
        foreach ($this->gauges as $name => $metric) {
            $result['gauge'][$name] = $metric->value();
        }
        foreach ($this->histograms as $name => $metric) {
            $result['histogram'][$name] = $metric->snapshot();
        }
        return $result;
    }
}
