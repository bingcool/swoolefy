<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Memory;

use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;

/**
 * 在请求路径之外定期采样 Worker 内存。
 */
final class MemoryMonitor
{
    private ?int $timerId = null;
    private ?MemoryTrend $trend = null;
    private ?MemorySnapshot $latestSnapshot = null;

    public function __construct(
        private readonly ?RuntimeMetrics $metrics,
        private readonly MemoryHistory $history,
        private readonly MemoryLeakDetector $detector,
        private readonly int $sampleInterval,
        private readonly int $workerStartedAt,
    ) {
    }

    /**
     * 仅启动一次监控器自身的定时器。
     *
     * 首次采样使 Worker 启动诊断能够立即使用；采样在请求路径外执行，
     * 并与 Worker 启动流程隔离。
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->sampleSafely();
        if (!class_exists(\Swoole\Timer::class)) {
            return;
        }

        $this->timerId = \Swoole\Timer::tick($this->sampleInterval, function (): void {
            $this->sampleSafely();
        });
    }

    /** 仅停止本监控器的定时器，并释放其有界历史记录。 */
    public function stop(): void
    {
        if ($this->timerId !== null && class_exists(\Swoole\Timer::class)) {
            \Swoole\Timer::clear($this->timerId);
        }
        $this->timerId = null;
        $this->history->clear();
        $this->trend = null;
        $this->latestSnapshot = null;
    }

    /** 采集标量运行时状态；测试中可直接调用。 */
    public function sample(): MemorySnapshot
    {
        $metrics = $this->metrics?->snapshot() ?? [];
        $requestCount = (int) ($metrics['counter'][RuntimeMetrics::HTTP_REQUESTS_TOTAL] ?? 0);
        $coroutine = class_exists(\Swoole\Coroutine::class) ? \Swoole\Coroutine::stats() : [];
        $snapshot = new MemorySnapshot(
            time(),
            $requestCount,
            memory_get_usage(false),
            memory_get_usage(true),
            memory_get_peak_usage(true),
            $this->readRss((int) getmypid()),
            (int) ($coroutine['coroutine_num'] ?? 0),
        );
        $this->latestSnapshot = $snapshot;
        // 在此更新 Gauge 而非在请求时更新，确保 RSS 读取不进入热点路径。
        $this->metrics?->recordWorkerMemory(
            max(0, time() - $this->workerStartedAt),
            $snapshot->phpRealUsage,
            $snapshot->phpPeakUsage,
            $snapshot->rss,
        );
        $this->history->push($snapshot);
        $this->trend = $this->detector->detect($this->history->all());
        return $snapshot;
    }

    /** 返回当前的有界分析结果。 */
    public function trend(): ?MemoryTrend
    {
        return $this->trend;
    }

    /** 返回最近一次定时或启动采样结果，不再次采集。 */
    public function latestSnapshot(): ?MemorySnapshot
    {
        return $this->latestSnapshot;
    }

    /** @return list<MemorySnapshot> */
    public function history(): array
    {
        return $this->history->all();
    }

    /** 仅在定时或诊断采样期间读取 Linux RSS，绝不在请求期间读取。 */
    private function readRss(int $pid): ?int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $file = "/proc/{$pid}/status";
        if (!is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $content, $matches) !== 1) {
            return null;
        }
        return (int) $matches[1] * 1024;
    }

    /**
     * 防止定时和启动采样失败影响应用代码。
     *
     * @return void
     */
    private function sampleSafely(): void
    {
        try {
            $this->sample();
        } catch (\Throwable $throwable) {
            // 监控必须以失败开放方式运行，且不得终止 Worker。
            \Swoolefy\Core\BaseServer::catchException($throwable);
        }
    }
}
