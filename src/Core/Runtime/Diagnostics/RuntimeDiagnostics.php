<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Diagnostics;

use Swoolefy\Cmd\Infrastructure\PidFileManager;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\CoroutineManager;
use Swoolefy\Core\Runtime\RuntimeRegistry;

/**
 * 以失败开放方式组装只读的 Worker 运行时诊断信息。
 *
 * 不包含配置、请求载荷、对象图、凭据或堆栈回溯。各采集器彼此隔离，
 * 因此部分诊断结果仍然可用。
 */
final class RuntimeDiagnostics
{
    /**
     * 创建安全的运行时快照。
     *
     * @param bool $memoryHistory 仅在显式请求时包含有界内存样本。
     * @return array<string, mixed>
     */
    public function snapshot(bool $memoryHistory = false): array
    {
        return [
            'runtime' => $this->runtime(),
            'process' => $this->process(),
            'worker' => $this->worker(),
            'server' => $this->server(),
            'coroutine' => $this->coroutine(),
            'memory' => $this->memory($memoryHistory),
            'metrics' => $this->metrics(),
            'pool' => $this->pool(),
        ];
    }

    /** 返回安全的运行时版本信息及已配置的服务协议。 */
    public function runtime(): array
    {
        return $this->collect(fn (): array => [
            'php_version' => PHP_VERSION,
            'swoole_version' => function_exists('swoole_version') ? swoole_version() : null,
            'swoolefy_version' => defined('SWOOLEFY_VERSION') ? SWOOLEFY_VERSION : null,
            'server_protocol' => BaseServer::getServiceProtocol(),
        ]);
    }

    /** 不调用操作系统 Shell，返回当前 Worker 进程标识。 */
    public function process(): array
    {
        $server = BaseServer::getServer();
        $pidFile = (string) $server->setting['pid_file'] ?? '';
        return $this->collect(static fn (): array => [
            'worker_pid' => getmypid() ?: null,
            'master_pid' =>  PidFileManager::read($pidFile),
            'manager_pid' => function_exists('posix_getppid') ? posix_getppid() : null,
        ]);
    }

    /** 仅使用公开的服务端状态构建 Worker 元数据。 */
    public function worker(): array
    {
        return $this->collect(function (): array {
            $server = BaseServer::getServer();
            return [
                'worker_id' => is_object($server) ? ($server->worker_id ?? null) : null,
                'worker_num' => is_object($server) ? ($server->setting['worker_num'] ?? null) : null,
                'start_time' => RuntimeRegistry::startedAt(),
                'uptime_seconds' => RuntimeRegistry::startedAt() === null ? null : max(0, time() - RuntimeRegistry::startedAt()),
            ];
        });
    }

    /** 复用 Swoole 服务端的标准统计数据，不自行维护副本。 */
    public function server(): array
    {
        return $this->collect(static fn (): array => BaseServer::getStats());
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

    /** 仅返回标量快照，绝不返回可变的指标对象。 */
    public function metrics(): array
    {
        return $this->collect(static fn (): array => RuntimeRegistry::metrics()?->snapshot() ?? ['enabled' => false]);
    }

    /** 返回连接池生命周期汇总计数器，不暴露连接池对象。 */
    public function pool(): array
    {
        return $this->collect(function (): array {
            $fetches = $this->poolTotal('swoolefy_pool_fetch_total');
            $releases = $this->poolTotal('swoolefy_pool_release_total');
            return [
                'fetch_total' => $fetches,
                'release_total' => $releases,
                'fetch_error_total' => $this->poolTotal('swoolefy_pool_fetch_error_total'),
                // 正余额仅是诊断信息，不能据此判定发生泄漏。
                'balance' => $fetches - $releases,
            ];
        });
    }

    /** 读取固定的汇总计数器，避免动态 Pool 键。 */
    private function poolTotal(string $name): int
    {
        $snapshot = RuntimeRegistry::metrics()?->snapshot() ?? [];
        return (int) ($snapshot['counter'][$name] ?? 0);
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
}
