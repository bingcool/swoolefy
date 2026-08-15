<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime;

use Swoolefy\Core\Runtime\Diagnostics\RuntimeDiagnostics;
use Swoolefy\Core\Runtime\Memory\MemoryHistory;
use Swoolefy\Core\Runtime\Memory\MemoryLeakDetector;
use Swoolefy\Core\Runtime\Memory\MemoryMonitor;
use Swoolefy\Core\Runtime\Metrics\MetricsRegistry;
use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;

/**
 * Worker 进程本地的可观测性生命周期注册表。
 *
 * PHP 静态变量由 Swoole Worker 进程隔离；本类只能在 Worker 生命周期回调中
 * 初始化和重置，不能在每个请求中执行。
 */
final class RuntimeRegistry
{
    private static ?RuntimeMetrics $metrics = null;
    private static ?MemoryMonitor $memory = null;
    private static ?RuntimeDiagnostics $diagnostics = null;
    private static ?int $startedAt = null;
    private static bool $initialized = false;

    /**
     * Cron Worker 注册的诊断快照提供者（通常闭包调用 CronManager::diagnostics()）。
     * HTTP Worker 保持 null，诊断中 cron.enabled=false。
     *
     * @var null|callable():array<string, mixed>
     */
    private static $cronSnapshot = null;

    /** 使用规范化且保守的运行时配置初始化一次。 */
    public static function initialize(array $config = []): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::$startedAt = time();
        $metricsEnabled = (bool) ($config['metrics']['enable'] ?? false);
        if ($metricsEnabled) {
            // 别名白名单由 WorkerStart 从 component_pools 传入；只在启动时确定，
            // 防止请求路径把任意字符串扩展为长期驻留的指标键。
            $poolAliases = array_values(array_filter(
                $config['pool_aliases'] ?? [],
                static fn (mixed $alias): bool => is_string($alias) && trim($alias) !== '',
            ));
            self::$metrics = new RuntimeMetrics(new MetricsRegistry(), $poolAliases);
        }
        // Diagnostics only reads existing Worker-local state on demand. Keep it
        // available by default; deployments that do not expose diagnostics incur
        // no request-path collection cost and can explicitly disable it.
        $diagnosticsEnabled = (bool) ($config['diagnostics']['enable'] ?? true);
        if ($diagnosticsEnabled) {
            self::$diagnostics = new RuntimeDiagnostics();
        }
        if ((bool) ($config['memory']['enable'] ?? false)) {
            $memory = $config['memory'] ?? [];
            $history = new MemoryHistory(max(10, (int) ($memory['history_size'] ?? 180)));
            self::$memory = new MemoryMonitor(
                self::$metrics,
                $history,
                new MemoryLeakDetector(
                    max(1, (int) ($memory['warmup_samples'] ?? 6)),
                    max(0, (int) ($memory['warning_growth'] ?? 64 * 1024 * 1024)),
                    max(0, (int) ($memory['critical_memory'] ?? 0)),
                    max(0, (int) ($memory['critical_rss'] ?? 0)),
                    min(1.0, max(0.0, (float) ($memory['positive_growth_ratio'] ?? 0.7))),
                    max(1, (int) ($memory['min_samples'] ?? 12)),
                ),
                max(1000, (int) ($memory['sample_interval'] ?? 10000)),
                self::$startedAt,
            );
            self::$memory->start();
        }
    }

    /** 返回 Worker 的指标适配器；禁用时返回 null。 */
    public static function metrics(): ?RuntimeMetrics
    {
        return self::$metrics;
    }

    /** 返回 Worker 的内存监控器；禁用时返回 null。 */
    public static function memory(): ?MemoryMonitor
    {
        return self::$memory;
    }

    /** 返回 Worker 诊断组件；仅在显式禁用时返回 null。 */
    public static function diagnostics(): ?RuntimeDiagnostics
    {
        return self::$diagnostics;
    }

    /** 返回供诊断使用的进程本地 Worker 启动时间戳。 */
    public static function startedAt(): ?int
    {
        return self::$startedAt;
    }

    /**
     * 注册 Cron 诊断快照提供者。
     *
     * CronProcess::runCronTask() 在 start() 前注册；onShutDown() 传 null 注销。
     * HTTP Worker 不注册则 RuntimeDiagnostics::cron() 返回 enabled=false。
     * 传入 null 表示显式清空，与 reset() 一致。
     *
     * @param null|callable():array<string, mixed> $provider
     */
    public static function registerCronSnapshot(?callable $provider): void
    {
        self::$cronSnapshot = $provider;
    }

    /**
     * 返回 Cron Runtime 诊断；未接入时返回 null。
     *
     * 提供者抛异常时返回 ['error'=>'collector_failed']，不把异常带出诊断通道。
     * 非 array 返回值视为无效，降级为 null。
     *
     * @return array<string, mixed>|null
     */
    public static function cronSnapshot(): ?array
    {
        if (self::$cronSnapshot === null) {
            return null;
        }
        try {
            $snapshot = (self::$cronSnapshot)();
            return is_array($snapshot) ? $snapshot : null;
        } catch (\Throwable) {
            return ['error' => 'collector_failed'];
        }
    }

    /** 在释放所有 Worker 本地引用之前停止定时器。 */
    public static function reset(): void
    {
        self::$memory?->stop();
        self::$memory = null;
        self::$diagnostics = null;
        self::$metrics = null;
        self::$cronSnapshot = null;
        self::$startedAt = null;
        self::$initialized = false;
    }
}
