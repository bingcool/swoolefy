<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Memory;

/**
 * 在不改变 Worker 行为的前提下，对有界内存历史进行分类。
 *
 * 检测器以 PHP 实际内存用量为主要信号；存在 RSS 时，只有 RSS 佐证
 * 内存增长才会将状态升级为 suspected。
 */
final class MemoryLeakDetector
{
    public const NORMAL = 'normal';
    public const OBSERVING = 'observing';
    public const SUSPECTED = 'suspected';
    public const CRITICAL = 'critical';

    public function __construct(
        private readonly int $warmupSamples,
        private readonly int $warningGrowthBytes,
        private readonly int $criticalMemoryBytes,
        private readonly int $criticalRssBytes,
        private readonly float $positiveGrowthRatio,
        private readonly int $minSamples,
    ) {
    }

    /** 分析样本并返回标量趋势结果，不保留样本。 */
    public function detect(array $samples): MemoryTrend
    {
        if ($samples === []) {
            return $this->trend(0, 0, 0, 0, 0.0, null, 0, null, self::NORMAL);
        }
        $last = $samples[array_key_last($samples)];
        if (!$last instanceof MemorySnapshot || count($samples) < $this->minSamples) {
            return $this->trend(0, $last->phpRealUsage, $last->phpPeakUsage, 0, 0.0, null, 0, null, self::NORMAL);
        }

        // 预热期中位数可避免将自动加载或启动阶段的内存分配误判为泄漏。
        $warmup = array_slice($samples, 0, min($this->warmupSamples, count($samples)));
        $values = array_map(static fn (MemorySnapshot $sample): int => $sample->phpRealUsage, $warmup);
        sort($values, SORT_NUMERIC);
        $baseline = $values[(int) floor((count($values) - 1) / 2)];
        $first = $samples[0];
        $growth = $last->phpRealUsage - $baseline;
        $rssGrowth = $last->rss !== null && $first->rss !== null ? $last->rss - $first->rss : null;
        $positive = 0;
        for ($i = 1, $count = count($samples); $i < $count; ++$i) {
            if ($samples[$i]->phpRealUsage > $samples[$i - 1]->phpRealUsage) {
                ++$positive;
            }
        }
        $ratio = $positive / max(1, count($samples) - 1);
        $requests = max(0, $last->requestCount - $first->requestCount);
        $per1k = $requests > 0 ? $growth / $requests * 1000 : null;
        $state = self::NORMAL;
        if (($this->criticalMemoryBytes > 0 && $last->phpRealUsage >= $this->criticalMemoryBytes)
            || ($this->criticalRssBytes > 0 && $last->rss !== null && $last->rss >= $this->criticalRssBytes)) {
            $state = self::CRITICAL;
        } elseif ($growth > 0) {
            $rssConfirms = $rssGrowth === null || $rssGrowth > 0;
            $state = $growth >= $this->warningGrowthBytes && $ratio >= $this->positiveGrowthRatio && $rssConfirms
                ? self::SUSPECTED : self::OBSERVING;
        }
        return $this->trend($baseline, $last->phpRealUsage, $last->phpPeakUsage, $growth, $baseline > 0 ? $growth / $baseline : 0.0, $rssGrowth, $requests, $per1k, $state, $ratio);
    }

    /** 为早期路径和常规路径一致地构建趋势结果。 */
    private function trend(int $baseline, int $current, int $peak, int $growth, float $rate, ?int $rssGrowth, int $requests, ?float $per1k, string $state, float $ratio = 0.0): MemoryTrend
    {
        return new MemoryTrend($baseline, $current, $peak, $growth, $rate, $ratio, $rssGrowth, $requests, $per1k, $state);
    }
}
