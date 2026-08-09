<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Memory;

/**
 * 固定大小的 Worker 内存及关联运行时活动观测记录。
 */
final class MemorySnapshot
{
    public function __construct(
        public readonly int $timestamp,
        public readonly int $requestCount,
        public readonly int $phpUsage,
        public readonly int $phpRealUsage,
        public readonly int $phpPeakUsage,
        public readonly ?int $rss,
        public readonly int $coroutineNum,
    ) {
    }

    /** @return array<string, int|null> */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'request_count' => $this->requestCount,
            'php_usage' => $this->phpUsage,
            'php_real_usage' => $this->phpRealUsage,
            'php_peak_usage' => $this->phpPeakUsage,
            'rss' => $this->rss,
            'coroutine_num' => $this->coroutineNum,
        ];
    }
}
