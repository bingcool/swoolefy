<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Diagnostics;

use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\CoroutineManager;
use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;
use Swoolefy\Core\Runtime\RuntimeRegistry;

/**
 * 以失败开放方式组装只读的服务与当前 Worker 运行时诊断信息。
 *
 * 不包含配置、请求载荷、对象图、凭据或堆栈回溯。各采集器彼此隔离，
 * 因此部分诊断结果仍然可用。
 */
final class RuntimeDiagnostics
{
    /** @var \Closure(): array<string, mixed> */
    private readonly \Closure $serverStats;

    /**
     * @param null|callable():array<string, mixed> $serverStats 仅供替换 Swoole 全局 stats 来源；
     *                                                       不得以 Worker 本地注册表替代。
     */
    public function __construct(?callable $serverStats = null)
    {
        $this->serverStats = $serverStats === null
            ? static fn (): array => BaseServer::getStats()
            : \Closure::fromCallable($serverStats);
    }

    /**
     * 创建安全的运行时快照。
     *
     * @param bool $memoryHistory 仅在显式请求时包含有界内存样本。
     * @return array<string, mixed>
     */
    public function snapshot(bool $memoryHistory = false): array
    {
        return [
            // global 只放服务级元数据或 Swoole 服务快照；绝不放 PHP Worker 本地状态。
            'global' => [
                'system' => $this->system(),
                'process' => $this->globalProcess(),
                'server' => $this->server(),
            ],
            'worker' => [
                'identity' => $this->workerIdentity(),
                'process' => $this->workerProcess(),
                'coroutine' => $this->coroutine(),
                'memory' => $this->memory($memoryHistory),
                'metrics' => $this->metrics(),
                'pool' => [
                    'aliases' => $this->pool(),
                ],
                'cron' => $this->cron(),
            ],
        ];
    }

    /** 返回服务级版本、协议及配置元数据；不包含随请求落点变化的 Worker 状态。 */
    public function system(): array
    {
        return $this->collect(function (): array {
            $server = BaseServer::getServer();
            return [
                'php_version' => PHP_VERSION,
                'swoole_version' => function_exists('swoole_version') ? swoole_version() : null,
                'swoolefy_version' => defined('SWOOLEFY_VERSION') ? SWOOLEFY_VERSION : null,
                'server_protocol' => BaseServer::getServiceProtocol(),
                'configured_worker_num' => is_object($server) ? ($server->setting['worker_num'] ?? null) : null,
            ];
        });
    }

    /** 返回从服务 PID 文件读取的 Master PID，不依赖当前 Worker 的父进程关系。 */
    public function globalProcess(): array
    {
        return $this->collect(static function (): array {
            $server = BaseServer::getServer();
            $pidFile = is_object($server) ? (string) ($server->setting['pid_file'] ?? '') : '';
            return [
                'master_pid' => PidFileManager::read($pidFile),
                'manager_pid' => $server->manager_pid
            ];
        });
    }

    /**
     * 返回当前 Worker 的进程标识。
     *
     * parent_pid 是 getppid() 读出的当前进程父 PID；不同部署/运行模式下不保证就是
     * Swoole manager，因此不能标记为服务级 manager_pid。
     */
    public function workerProcess(): array
    {
        return $this->collect(static fn (): array => [
            'pid' => getmypid() ?: null,
            'parent_pid' => function_exists('posix_getppid') ? posix_getppid() : null,
        ]);
    }

    /** 仅返回承载本次响应的当前 Worker 身份与生命周期。 */
    public function workerIdentity(): array
    {
        return $this->collect(function (): array {
            $server = BaseServer::getServer();
            return [
                'worker_id' => is_object($server) ? ($server->worker_id ?? null) : null,
                'start_time' => RuntimeRegistry::startedAt(),
                'uptime_seconds' => RuntimeRegistry::startedAt() === null ? null : max(0, time() - RuntimeRegistry::startedAt()),
            ];
        });
    }

    /** 复用 Swoole 服务端的标准统计数据，不自行维护副本。 */
    public function server(): array
    {
        return $this->collect($this->serverStats);
    }

    /** 复用框架 CoroutineManager 的版本兼容状态 API。 */
    public function coroutine(): array
    {
        return $this->collect(static fn (): array => CoroutineManager::getInstance()->getCoroutineStatus());
    }

    /** 返回内存状态，不强制采样或读取 /proc。 */
    public function memory(bool $includeHistory = false): array
    {
        return $this->collect(function () use ($includeHistory): array {
            $monitor = RuntimeRegistry::memory();
            if ($monitor === null) {
                return ['enabled' => false];
            }
            $trend = $monitor->trend()?->toArray() ?? ['state' => 'normal'];
            // 监控器持有最近的有界标量样本；诊断不可只为生成快照而
            // 强制再次读取 /proc。
            $sample = $monitor->latestSnapshot();
            if ($sample !== null) {
                $trend = array_merge($sample->toArray(), $trend);
            }
            if ($includeHistory) {
                $trend['samples'] = array_map(static fn ($sample): array => $sample->toArray(), $monitor->history());
            }
            return $trend;
        });
    }

    /**
     * 返回当前 Worker 的本地指标；服务级 Swoole 统计唯一位于 global.server。
     *
     * RuntimeRegistry 是当前 Worker 的 PHP 静态状态，不能用它构造伪造的服务汇总。
     */
    public function metrics(): array
    {
        return $this->collect(function (): array {
            $metrics = RuntimeRegistry::metrics();
            if ($metrics === null) {
                return ['enabled' => false];
            }

            $snapshot = $metrics->snapshot();
            $counter = $snapshot['counter'];
            $gauge = $snapshot['gauge'];
            $histogram = $snapshot['histogram'];

            return [
                'request' => [
                    'counter' => $this->only($counter, [
                        RuntimeMetrics::HTTP_REQUESTS_TOTAL,
                        RuntimeMetrics::HTTP_5XX_TOTAL,
                        RuntimeMetrics::WORKER_REQUESTS_TOTAL,
                        RuntimeMetrics::WORKER_ERRORS_TOTAL,
                    ]),
                    'gauge' => $this->only($gauge, [RuntimeMetrics::HTTP_REQUESTS_ACTIVE]),
                    'histogram' => $this->only($histogram, [RuntimeMetrics::HTTP_DURATION]),
                ],
                'cron' => [
                    'gauge' => $this->only($gauge, [
                        RuntimeMetrics::CRON_JOBS_TOTAL,
                        RuntimeMetrics::CRON_JOBS_ENABLED,
                        RuntimeMetrics::CRON_JOBS_RUNNING,
                    ]),
                    'counter' => $this->only($counter, [
                        RuntimeMetrics::CRON_RUNS_TOTAL,
                        RuntimeMetrics::CRON_RUNS_SUCCESS,
                        RuntimeMetrics::CRON_RUNS_FAILED,
                        RuntimeMetrics::CRON_RUNS_SKIPPED,
                    ]),
                    'histogram' => $this->only($histogram, [RuntimeMetrics::CRON_EXECUTION_DURATION]),
                ],
                'memory' => [
                    'gauge' => $this->only($gauge, [
                        RuntimeMetrics::WORKER_UPTIME_SECONDS,
                        RuntimeMetrics::WORKER_MEMORY_BYTES,
                        RuntimeMetrics::WORKER_PEAK_MEMORY_BYTES,
                        RuntimeMetrics::WORKER_RSS_BYTES,
                    ]),
                ],
                'pool' => [
                    'counter' => $this->only($counter, [
                        RuntimeMetrics::POOL_FETCH_TOTAL,
                        RuntimeMetrics::POOL_RELEASE_TOTAL,
                        RuntimeMetrics::POOL_FETCH_ERROR_TOTAL,
                        RuntimeMetrics::POOL_UNATTRIBUTED_TOTAL,
                    ]),
                ],
            ];
        });
    }

    /**
     * 返回按组件池别名归因的当前 Worker 生命周期计数器，不暴露连接池对象。
     *
     * 未知或缺失别名不会出现在此映射中，而是由
     * worker.metrics.pool.counter.swoolefy_pool_unattributed_total 明确记录。
     *
     * @return array<string, array{fetch_total:int,release_total:int,fetch_error_total:int,balance:int}>
     */
    public function pool(): array
    {
        return $this->collect(
            static fn (): array => RuntimeRegistry::metrics()?->poolSnapshot() ?? [],
        );
    }

    /**
     * 复用 CronManager 经 RuntimeRegistry 注册的诊断快照。
     *
     * 内容由 CronManager::diagnostics() 提供：job_count / enabled_count /
     * running_count / last_config_sync / last_config_sync_error / jobs。
     * HTTP Worker 未接入时返回 ['enabled'=>false]，不另建第二套 Cron 诊断。
     * 采集失败走 collect() 的 collector_failed，不暴露堆栈。
     *
     * @return array<string, mixed>
     */
    public function cron(): array
    {
        return $this->collect(static function (): array {
            $snapshot = RuntimeRegistry::cronSnapshot();
            if ($snapshot === null) {
                return ['enabled' => false];
            }

            return $snapshot;
        });
    }

    /** 将采集器失败转换为不透明且不含敏感信息的响应。 */
    private function collect(callable $collector): array
    {
        try {
            return $collector();
        } catch (\Throwable) {
            return ['error' => 'collector_failed'];
        }
    }

    /**
     * 从有界的固定指标名中选取分类快照，缺失表示该指标尚未在当前 Worker 创建。
     *
     * @template TValue
     * @param array<string, TValue> $metrics
     * @param list<string> $names
     * @return array<string, TValue>
     */
    private function only(array $metrics, array $names): array
    {
        return array_filter(
            $metrics,
            static fn (mixed $value, string $name): bool => in_array($name, $names, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
