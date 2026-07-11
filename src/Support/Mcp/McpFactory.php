<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Security\OutboundUrlGuard;
use Swoolefy\Support\SupportLog;

/**
 * MCP Server 配置工厂。
 *
 * 职责：解析配置 → 安全校验 → 创建 McpConnector → 发现 Tools。
 *
 * 配置解析优先级（{@see resolveConfig()}）：
 *   1. Repository 行（mcp_server_configs 全局基础配置）
 *   2. 构造函数静态 $servers map
 *   3. 均未命中 → disabled stub（不抛错，tools() 返回空）
 *
 * Neuron MCP 映射：
 *   - command + args：本地 stdio MCP（生产默认禁用，需 allowlist）
 *   - url + token/headers/timeout：远程 Streamable HTTP MCP
 *   - url + async=true：远程 SSE MCP
 *   - only/exclude：按 server 限制可暴露给 Agent 的 tool 名称
 *
 * 安全链：McpStdioGuard（stdio 禁用/allowlist）→ OutboundUrlGuard（url 白名单）
 *
 * 失败策略：单个 Server 加载失败时 SupportLog::warning 并跳过，不阻断其它 Server。
 *
 * @see https://docs.neuron-ai.dev/agent/mcp-connector
 */
final class McpFactory
{
    private readonly McpStdioGuard $stdioGuard;

    private readonly ?OutboundUrlGuard $urlGuard;

    /**
     * @param array<string, array<string, mixed>> $servers 静态配置 map（neuron_ai.php 或构造注入）
     */
    public function __construct(
        private readonly array $servers = [],
        private readonly ?McpServerConfigRepositoryInterface $repository = null,
        private readonly ?McpProcessRunner $processRunner = null,
        ?McpStdioGuard $stdioGuard = null,
        ?OutboundUrlGuard $urlGuard = null,
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
    public function serverNames(): array
    {
        $names = array_keys($this->servers);
        if ($this->repository !== null) {
            foreach ($this->repository->list() as $config) {
                $names[] = $config->server_id;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * 创建单个 MCP 连接器。
     *
     * 未命中配置时返回 transport=disabled 的 stub，调用方不会抛错。
     */
    public function connector(string $name): McpConnector
    {
        $config = $this->resolveConfig($name);
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
     * @param array<string, list<string>>|null     $only    按 server 过滤 tool 名；对应 Neuron McpConnector::only()
     * @param array<string, list<string>>|null     $exclude 按 server 排除 tool 名；对应 Neuron McpConnector::exclude()
     * @param string|null                          $tenantId 仅用于日志上下文（请求 tenant），不参与配置解析
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

            $config = $this->resolveConfig($name);
            $isLocal = McpProcessRunner::isLocalStdioConfig($config);
            $runner = $this->processRunner;

            try {
                if ($config !== []) {
                    $this->assertConfigSafe($config, $name);
                }

                if ($isLocal && $runner !== null) {
                    $runner->acquire($name);
                }

                $connector = $this->connector($name);
                if ($only !== null && isset($only[$name]) && is_array($only[$name])) {
                    $toolNames = $this->normalizeToolFilter($only[$name]);
                    if ($toolNames !== []) {
                        $connector = $connector->only($toolNames);
                    }
                }
                if ($exclude !== null && isset($exclude[$name]) && is_array($exclude[$name])) {
                    $toolNames = $this->normalizeToolFilter($exclude[$name]);
                    if ($toolNames !== []) {
                        $connector = $connector->exclude($toolNames);
                    }
                }
                $tools = [...$tools, ...$connector->tools()];
            } catch (\Throwable $e) {
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
    public function listServers(): array
    {
        $byServerId = [];

        foreach ($this->serverNames() as $name) {
            $config = $this->resolveConfig($name);
            $byServerId[$name] = [
                'server_id' => $name,
                'transport' => McpServerConfig::detectTransport($config),
                'enabled' => $config !== [] && (($config['transport'] ?? '') !== 'disabled'),
            ];
        }

        if ($this->repository !== null) {
            foreach ($this->repository->list() as $row) {
                $byServerId[$row->server_id] = $row->toPublicArray();
            }
        }

        return array_values($byServerId);
    }

    /** @return list<string> tool 名称列表（单 server 失败时 fail-soft，返回已收集到的名称） */
    public function listToolNames(string $name): array
    {
        $tools = $this->tools([$name]);

        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    /**
     * 发现单个 Server 的 tool 名称（供 Capability sync 使用）。
     *
     * 与 {@see listToolNames()} / {@see tools()} 的区别：
     * - transport=disabled 或配置缺失 → 返回空列表（视为「该 server 无工具」）；
     * - 已配置但连接/列举失败 → **抛出异常**，由调用方决定是否保留旧元数据。
     *
     * @return list<string>
     *
     * @throws \Throwable
     */
    public function discoverToolNames(string $name): array
    {
        $config = $this->resolveConfig($name);
        if ($config === [] || (($config['transport'] ?? '') === 'disabled')) {
            return [];
        }

        $this->assertConfigSafe($config, $name);

        $isLocal = McpProcessRunner::isLocalStdioConfig($config);
        $runner = $this->processRunner;

        try {
            if ($isLocal && $runner !== null) {
                $runner->acquire($name);
            }

            $tools = $this->connector($name)->tools();

            return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
        } finally {
            if ($isLocal && $runner !== null) {
                $runner->release();
            }
        }
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
     * 归一化 only/exclude 工具名列表。
     *
     * @param array<mixed> $tools
     *
     * @return list<string>
     */
    private function normalizeToolFilter(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            if (is_string($tool) && $tool !== '') {
                $names[] = $tool;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * 按优先级解析 MCP Server 原始配置数组。
     *
     * @return array<string, mixed> 空数组表示未配置
     */
    private function resolveConfig(string $name): array
    {
        if ($this->repository !== null) {
            $row = $this->repository->find($name);
            if ($row !== null && $row->enabled) {
                return $row->config;
            }
        }

        if (isset($this->servers[$name]) && is_array($this->servers[$name])) {
            return $this->servers[$name];
        }

        return [];
    }
}
