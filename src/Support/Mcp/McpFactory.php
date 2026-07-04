<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;

/**
 * MCP Server 配置工厂 —— 静态配置 + 多租户仓储 + 本地 stdio 进程限流。
 *
 * 配置解析优先级（高 → 低）：
 *   1. 构造函数 $servers 静态 map
 *   2. McpServerConfigRepository 按 tenantId 查询
 *   3. 均未命中 → disabled stub（不抛错，tools() 返回空）
 *
 * 技术要点：
 * - 远程 HTTP/SSE：与 Provider/RAG embed 共用 CurlProxy 协程 HTTP（Neuron 侧）
 * - 本地 stdio：tools() 前后 acquire/release，受 McpProcessRunner 并发限制
 * - only/exclude：Neuron McpConnector 白/黑名单，控制 token 与误调用风险
 *
 * @see docs/swoolefyAI.md §4.11.2、§4.11.4
 */
final class McpFactory
{
    /**
     * @param array<string, array<string, mixed>>              $servers    静态命名 Server 配置
     * @param McpServerConfigRepositoryInterface|null          $repository 多租户 DB/内存仓储
     * @param McpProcessRunner|null                            $processRunner 本地 stdio 限流
     */
    public function __construct(
        private readonly array $servers = [],
        private readonly ?McpServerConfigRepositoryInterface $repository = null,
        private readonly ?McpProcessRunner $processRunner = null,
    ) {
    }

    /**
     * 已注册 Server 名称列表（静态 + 仓储合并去重）。
     *
     * @return list<string>
     */
    public function serverNames(?string $tenantId = null): array
    {
        $names = array_keys($this->servers);
        if ($this->repository !== null) {
            foreach ($this->repository->list($tenantId) as $config) {
                $names[] = $config->id;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * 获取 Neuron McpConnector 实例。
     *
     * 未配置时返回 transport=disabled 的 stub，避免 Agent 构建失败。
     */
    public function connector(string $name, ?string $tenantId = null): McpConnector
    {
        $config = $this->resolveConfig($name, $tenantId);
        if ($config === []) {
            return McpConnector::make(['transport' => 'disabled', 'name' => $name]);
        }

        return McpConnector::make($config);
    }

    /**
     * 批量发现并合并多个 MCP Server 的 Tool 列表。
     *
     * @param list<string>              $names    Server 名称列表
     * @param array<string, list<string>>|null $only     按 Server 白名单工具名
     * @param array<string, list<string>>|null $exclude  按 Server 黑名单工具名
     * @param string|null               $tenantId 租户 ID（多租户仓储过滤）
     *
     * @return list<ToolInterface>
     */
    public function tools(array $names, ?array $only = null, ?array $exclude = null, ?string $tenantId = null): array
    {
        $tools = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $config = $this->resolveConfig($name, $tenantId);
            $isLocal = McpProcessRunner::isLocalStdioConfig($config);
            $runner = $this->processRunner;

            try {
                // 本地 stdio：子进程阻塞 Worker，必须限流
                if ($isLocal && $runner !== null) {
                    $runner->acquire($name);
                }

                $connector = $this->connector($name, $tenantId);
                if ($only !== null && isset($only[$name]) && is_array($only[$name])) {
                    $connector = $connector->only($only[$name]);
                }
                if ($exclude !== null && isset($exclude[$name]) && is_array($exclude[$name])) {
                    $connector = $connector->exclude($exclude[$name]);
                }
                $tools = [...$tools, ...$connector->tools()];
            } catch (\Throwable) {
                // 生产应记录日志；MCP 不可达时不阻断主 Workflow
            } finally {
                if ($isLocal && $runner !== null) {
                    $runner->release();
                }
            }
        }

        return $tools;
    }

    /**
     * 列出 Server 公开信息（API 用，凭证脱敏）。
     *
     * @return list<array<string, mixed>>
     */
    public function listServers(?string $tenantId = null): array
    {
        $byId = [];

        foreach ($this->serverNames($tenantId) as $name) {
            $config = $this->resolveConfig($name, $tenantId);
            $byId[$name] = [
                'id' => $name,
                'tenantId' => $tenantId,
                'transport' => McpServerConfig::detectTransport($config),
                'enabled' => $config !== [] && (($config['transport'] ?? '') !== 'disabled'),
            ];
        }

        // 仓储条目覆盖静态条目（含 description 等扩展字段）
        if ($this->repository !== null) {
            foreach ($this->repository->list($tenantId) as $row) {
                $byId[$row->id] = $row->toPublicArray();
            }
        }

        return array_values($byId);
    }

    /**
     * 发现指定 Server 的工具名列表（运维 / GET /mcp/servers/{id}/tools）。
     *
     * @return list<string>
     */
    public function listToolNames(string $name, ?string $tenantId = null): array
    {
        $tools = $this->tools([$name], tenantId: $tenantId);

        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    /**
     * 合并静态配置与仓储配置。
     *
     * @return array<string, mixed> Neuron McpConnector 兼容配置
     */
    private function resolveConfig(string $name, ?string $tenantId): array
    {
        if (isset($this->servers[$name]) && is_array($this->servers[$name])) {
            return $this->servers[$name];
        }

        if ($this->repository !== null) {
            $row = $this->repository->find($name, $tenantId);
            if ($row !== null && $row->enabled) {
                return $row->config;
            }
        }

        return [];
    }
}
