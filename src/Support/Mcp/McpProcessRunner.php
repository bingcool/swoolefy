<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * 本地 stdio MCP 子进程并发守卫 —— 避免 Swoole Worker 被过多子进程阻塞。
 *
 * 技术要点：
 * - 生产优先远程 HTTP/SSE MCP；stdio 仅适合开发或内网脚本
 * - acquire/release 包裹 McpFactory::tools() 中 connector.tools() 调用
 * - 进程内 static 计数（单 Worker 维度）；多 Worker 需 Redis 分布式信号量（Phase 5）
 * - MCP_MAX_LOCAL_PROCESSES 默认 2
 *
 * @see swoolefyAI.md §4.11.4
 */
final class McpProcessRunner
{
    /** 当前 Worker 内活跃本地 MCP 子进程数。 */
    private static int $active = 0;

    /**
     * @param int $maxLocalProcesses 单 Worker 允许的最大并发 stdio 子进程数
     */
    public function __construct(
        private readonly int $maxLocalProcesses = 2,
    ) {
    }

    /** 从环境变量 MCP_MAX_LOCAL_PROCESSES 构建（最小值 1）。 */
    public static function fromEnv(): self
    {
        return new self(NeuronAiConfig::load()->maxLocalProcesses());
    }

    /**
     * 申请本地进程槽位。
     *
     * @param string $serverName 用于错误信息定位
     *
     * @throws McpProcessLimitException 超出 maxLocalProcesses
     */
    public function acquire(string $serverName): void
    {
        if (self::$active >= $this->maxLocalProcesses) {
            throw new McpProcessLimitException(
                "Local MCP process limit reached ({$this->maxLocalProcesses}) for server {$serverName}",
            );
        }

        self::$active++;
    }

    /** 释放槽位（finally 中调用，防止泄漏）。 */
    public function release(): void
    {
        if (self::$active > 0) {
            self::$active--;
        }
    }

    /**
     * 判断 Neuron 配置是否为本地 stdio 模式（含 command 字段）。
     *
     * @param array<string, mixed> $config
     */
    public static function isLocalStdioConfig(array $config): bool
    {
        return isset($config['command']) && is_string($config['command']) && $config['command'] !== '';
    }

    /** 当前活跃数（Metrics / 单测观测）。 */
    public static function activeCount(): int
    {
        return self::$active;
    }

    /** 单测隔离：重置计数。 */
    public static function reset(): void
    {
        self::$active = 0;
    }
}
