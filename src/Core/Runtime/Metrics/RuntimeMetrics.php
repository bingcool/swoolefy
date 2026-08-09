<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 由 Worker 本地注册表支持的精简框架埋点 API。
 *
 * Pool 名称仅为未来兼容性而接收；当前实现保证指标基数安全，
 * 仅记录连接池汇总值。
 */
final class RuntimeMetrics
{
    public const HTTP_REQUESTS_TOTAL = 'swoolefy_http_requests_total';
    public const HTTP_REQUESTS_ACTIVE = 'swoolefy_http_requests_active';
    public const HTTP_5XX_TOTAL = 'swoolefy_http_5xx_total';
    public const HTTP_DURATION = 'swoolefy_http_request_duration_seconds';
    public const WORKER_REQUESTS_TOTAL = 'swoolefy_worker_requests_total';
    public const WORKER_ERRORS_TOTAL = 'swoolefy_worker_errors_total';
    public const WORKER_UPTIME_SECONDS = 'swoolefy_worker_uptime_seconds';
    public const WORKER_MEMORY_BYTES = 'swoolefy_worker_memory_bytes';
    public const WORKER_PEAK_MEMORY_BYTES = 'swoolefy_worker_peak_memory_bytes';
    public const WORKER_RSS_BYTES = 'swoolefy_worker_rss_bytes';
    public const POOL_FETCH_TOTAL = 'swoolefy_pool_fetch_total';
    public const POOL_RELEASE_TOTAL = 'swoolefy_pool_release_total';
    public const POOL_FETCH_ERROR_TOTAL = 'swoolefy_pool_fetch_error_total';

    public function __construct(private readonly MetricsRegistry $registry)
    {
    }

    /** 将应用请求标记为进行中，并累计请求数。 */
    public function requestStarted(): void
    {
        $this->registry->counter(self::HTTP_REQUESTS_TOTAL)->increment();
        $this->registry->counter(self::WORKER_REQUESTS_TOTAL)->increment();
        $this->registry->gauge(self::HTTP_REQUESTS_ACTIVE)->increment();
    }

    /** 将应用请求标记为不再进行中。 */
    public function requestFinished(): void
    {
        $this->registry->gauge(self::HTTP_REQUESTS_ACTIVE)->decrement();
    }

    /** 记录未捕获的应用异常，并视为 5xx 结果。 */
    public function requestError(): void
    {
        $this->registry->counter(self::HTTP_5XX_TOTAL)->increment();
        $this->registry->counter(self::WORKER_ERRORS_TOTAL)->increment();
    }

    /** 使用单调时钟记录请求耗时（秒）。 */
    public function requestDuration(float $seconds): void
    {
        $this->registry->histogram(self::HTTP_DURATION)->observe($seconds);
    }

    /** 记录从现有组件池成功获取对象。 */
    public function poolFetched(string $name): void
    {
        $this->registry->counter(self::POOL_FETCH_TOTAL)->increment();
    }

    /** 记录对象成功归还至现有组件池。 */
    public function poolReleased(string $name): void
    {
        $this->registry->counter(self::POOL_RELEASE_TOTAL)->increment();
    }

    /** 记录组件池获取失败或返回 null。 */
    public function poolFetchError(string $name): void
    {
        $this->registry->counter(self::POOL_FETCH_ERROR_TOTAL)->increment();
    }

    /** 根据定时内存采样器更新 Worker 运行时 Gauge。 */
    public function recordWorkerMemory(int $uptimeSeconds, int $usageBytes, int $peakBytes, ?int $rssBytes): void
    {
        $this->registry->gauge(self::WORKER_UPTIME_SECONDS)->set($uptimeSeconds);
        $this->registry->gauge(self::WORKER_MEMORY_BYTES)->set($usageBytes);
        $this->registry->gauge(self::WORKER_PEAK_MEMORY_BYTES)->set($peakBytes);
        if ($rssBytes !== null) {
            $this->registry->gauge(self::WORKER_RSS_BYTES)->set($rssBytes);
        }
    }

    /** 返回用于诊断的不可变标量快照。 */
    public function snapshot(): array
    {
        return $this->registry->snapshot();
    }
}
