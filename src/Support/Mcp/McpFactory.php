<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\SupportLog;

/**
 * MCP Server 配置工厂。
 *
 * 职责：解析配置 → 安全校验 → 创建 McpConnector → 发现 Tools。
 *
 * 配置解析优先级（{@see resolveConfig()}）：
 *   1. Repository 租户专属（tenantId 非空）
 *   2. Repository 全局（tenant_id 空）
 *   3. 构造函数静态 $servers map
 *   4. 均未命中 → disabled stub（不抛错，tools() 返回空）
 *
 * 安全链：McpStdioGuard（stdio 禁用/allowlist）→ OutboundUrlGuard（url 白名单）
 *
 * 失败策略：单个 Server 加载失败时 SupportLog::warning 并跳过，不阻断其它 Server。
 */
final class McpFactory
{
    private readonly McpStdioGuard $stdioGuard;

    private readonly ?\Swoolefy\Support\Security\OutboundUrlGuard $urlGuard;

    /**
     * @param array<string, array<string, mixed>> $servers 静态配置 map（neuron_ai.php 或构造注入）
     */
    public function __construct(
        private readonly array $servers = [],
        private readonly ?McpServerConfigRepositoryInterface $repository = null,
        private readonly ?McpProcessRunner $processRunner = null,
        ?McpStdioGuard $stdioGuard = null,
        ?\Swoolefy\Support\Security\OutboundUrlGuard $urlGuard = null,
        ?NeuronAiConfig $config = null,
    ) {
        $config ??= NeuronAiConfig::load();
        $this->stdioGuard = $stdioGuard ?? $config->mcpStdioGuard();
        $this->urlGuard = $urlGuard ?? $config->outboundUrlGuard();
    }

    /**
     * 合并静态 servers 与 Repository 中的 server_id 列表。
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
     * 创建单个 MCP 连接器。
     *
     * 未命中配置时返回 transport=disabled 的 stub，调用方不会抛错。
     */
    public function connector(string $name, ?string $tenantId = null): McpConnector
    {
        $config = $this->resolveConfig($name, $tenantId);
        if ($config === []) {
            return McpConnector::make(['transport' => 'disabled', 'name' => $name]);
        }

        $this->assertConfigSafe($config, $name);

        return McpConnector::make($config);
    }

    /**
     * 批量加载多个 Server 的 Tools。
     *
     * stdio Server 会在 acquire/release 间受 McpProcessRunner 并发限制。
     * 单个 Server 异常时记录日志并继续处理其余 Server。
     *
     * @param list<string>                         $names
     * @param array<string, list<string>>|null     $only    按 server 过滤 tool 名
     * @param array<string, list<string>>|null     $exclude 按 server 排除 tool 名
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
                if ($config !== []) {
                    $this->assertConfigSafe($config, $name);
                }

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
            } catch (\Throwable $e) {
                // Phase A：失败打日志，不再静默吞掉
                SupportLog::warning('mcp', 'Failed to load MCP tools', [
                    'server' => $name,
                    'tenantId' => $tenantId,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            } finally {
                if ($isLocal && $runner !== null) {
                    $runner->release();
                }
            }
        }

        return $tools;
    }

    /**
     * 列出 Server 公开信息（凭证脱敏）。
     *
     * Repository 行会覆盖静态解析结果，以 DB 中的 description/enabled 为准。
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

        if ($this->repository !== null) {
            foreach ($this->repository->list($tenantId) as $row) {
                $byId[$row->id] = $row->toPublicArray();
            }
        }

        return array_values($byId);
    }

    /** @return list<string> tool 名称列表 */
    public function listToolNames(string $name, ?string $tenantId = null): array
    {
        $tools = $this->tools([$name], tenantId: $tenantId);

        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    /**
     * 连接前安全校验：stdio 守卫 + 出站 URL 白名单。
     *
     * @param array<string, mixed> $config
     */
    private function assertConfigSafe(array $config, string $serverName): void
    {
        $this->stdioGuard->assertAllowed($config, $serverName);

        if ($this->urlGuard !== null && isset($config['url']) && is_string($config['url']) && $config['url'] !== '') {
            $this->urlGuard->assertAllowed($config['url'], 'mcp:' . $serverName);
        }
    }

    /**
     * 按优先级解析 MCP Server 原始配置数组。
     *
     * @return array<string, mixed> 空数组表示未配置
     */
    private function resolveConfig(string $name, ?string $tenantId): array
    {
        // 1. 租户专属 DB 行
        if ($this->repository !== null && $tenantId !== null && $tenantId !== '') {
            $row = $this->repository->find($name, $tenantId);
            if ($row !== null && $row->enabled) {
                return $row->config;
            }
        }

        // 2. 全局 DB 行（tenant_id=''）
        if ($this->repository !== null) {
            $global = $this->repository->find($name, null);
            if ($global !== null && $global->enabled) {
                return $global->config;
            }
        }

        // 3. 静态 neuron_ai.php / 构造注入
        if (isset($this->servers[$name]) && is_array($this->servers[$name])) {
            return $this->servers[$name];
        }

        return [];
    }
}
