<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

/**
 * 由 Worker 本地注册表支持的精简框架埋点 API。
 *
 * 除固定汇总指标外，连接池计数仅按 Worker 启动时已配置的组件别名分组。
 * 未知、空白或运行中才出现的别名不会创建新键，而会进入固定的未归属计数器，
 * 从而避免高基数，也不会把数据错误归到某个已知连接池。
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
    public const POOL_UNATTRIBUTED_TOTAL = 'swoolefy_pool_unattributed_total';

    /**
     * @var array<string, array{fetch_total:int,release_total:int,fetch_error_total:int}>
     */
    private array $poolMetrics = [];

    /**
     * @param list<string> $poolAliases Worker 启动时从 component_pools 读取的受信任别名白名单。
     */
    public function __construct(private readonly MetricsRegistry $registry, array $poolAliases = [])
    {
        foreach ($poolAliases as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                $this->poolMetrics[trim($alias)] = [
                    'fetch_total' => 0,
                    'release_total' => 0,
                    'fetch_error_total' => 0,
                ];
            }
        }
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
        $this->incrementPoolMetric($name, 'fetch_total');
    }

    /** 记录对象成功归还至现有组件池。 */
    public function poolReleased(string $name): void
    {
        $this->registry->counter(self::POOL_RELEASE_TOTAL)->increment();
        $this->incrementPoolMetric($name, 'release_total');
    }

    /** 记录组件池获取失败或返回 null。 */
    public function poolFetchError(string $name): void
    {
        $this->registry->counter(self::POOL_FETCH_ERROR_TOTAL)->increment();
        $this->incrementPoolMetric($name, 'fetch_error_total');
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

    /**
     * 返回当前 Worker 的不可变标量快照。
     *
     * RuntimeRegistry 和本对象都保存在 Worker 进程的 PHP 内存中；此快照绝不能
     * 作为整个服务的全局聚合值。跨 Worker 的服务级指标应读取 Swoole Server::stats()。
     */
    public function snapshot(): array
    {
        return $this->registry->snapshot();
    }

    /**
     * 返回每个已配置组件池的生命周期快照。
     *
     * 快照在单个 Worker、单线程协程调度模型中读取；每次加一没有 yield，因此不会出现
     * 半次更新。不同指标读取之间仍可能穿插协程调度，调用方应将其视为诊断近似值。
     *
     * @return array<string, array{fetch_total:int,release_total:int,fetch_error_total:int,balance:int}>
     */
    public function poolSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->poolMetrics as $alias => $metrics) {
            $snapshot[$alias] = [
                'fetch_total' => $metrics['fetch_total'],
                'release_total' => $metrics['release_total'],
                'fetch_error_total' => $metrics['fetch_error_total'],
                // 正余额仅是诊断信号，不能据此断言发生资源泄漏。
                'balance' => $metrics['fetch_total'] - $metrics['release_total'],
            ];
        }

        return $snapshot;
    }

    /**
     * 增加已知别名的指标；未知输入只计入一个固定计数器，绝不动态创建别名键。
     *
     * @param 'fetch_total'|'release_total'|'fetch_error_total' $metric
     */
    private function incrementPoolMetric(string $name, string $metric): void
    {
        $alias = trim($name);
        if ($alias === '' || !isset($this->poolMetrics[$alias])) {
            // 记录可观测的拒绝结果，避免无声丢失或错误归属到其他组件池。
            $this->registry->counter(self::POOL_UNATTRIBUTED_TOTAL)->increment();
            return;
        }

        ++$this->poolMetrics[$alias][$metric];
    }
}
