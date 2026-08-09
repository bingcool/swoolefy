<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Memory;

/**
 * 可通过诊断安全暴露的标量内存泄漏分析结果。
 */
final class MemoryTrend
{
    public function __construct(
        public readonly int $baseline,
        public readonly int $current,
        public readonly int $peak,
        public readonly int $growth,
        public readonly float $growthRate,
        public readonly float $positiveGrowthRatio,
        public readonly ?int $rssGrowth,
        public readonly int $requestsDelta,
        public readonly ?float $memoryPer1kRequests,
        public readonly string $state,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'baseline' => $this->baseline,
            'current' => $this->current,
            'peak' => $this->peak,
            'growth' => $this->growth,
            'growth_rate' => $this->growthRate,
            'positive_growth_ratio' => $this->positiveGrowthRatio,
            'rss_growth' => $this->rssGrowth,
            'requests_delta' => $this->requestsDelta,
            'memory_per_1k_requests' => $this->memoryPer1kRequests,
            'state' => $this->state,
        ];
    }
}
