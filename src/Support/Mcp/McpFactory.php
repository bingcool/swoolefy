<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

use NeuronAI\MCP\McpConnector;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\SupportLog;

/**
 * MCP Server 配置工厂 —— 静态配置 + 多租户仓储 + 本地 stdio 进程限流。
 *
 * 配置解析优先级（tenantId 存在时）：
 *   1. Repository 租户专属
 *   2. Repository 全局（tenant_id 空）
 *   3. 静态 servers map
 *   4. 均未命中 → disabled stub
 */
final class McpFactory
{
    private readonly McpStdioGuard $stdioGuard;

    private readonly ?\Swoolefy\Support\Security\OutboundUrlGuard $urlGuard;

    /**
     * @param array<string, array<string, mixed>> $servers
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

    /** @return list<string> */
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
     * @param list<string> $names
     * @param array<string, list<string>>|null $only
     * @param array<string, list<string>>|null $exclude
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

    /** @return list<array<string, mixed>> */
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

    /** @return list<string> */
    public function listToolNames(string $name, ?string $tenantId = null): array
    {
        $tools = $this->tools([$name], tenantId: $tenantId);

        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    /** @param array<string, mixed> $config */
    private function assertConfigSafe(array $config, string $serverName): void
    {
        $this->stdioGuard->assertAllowed($config, $serverName);

        if ($this->urlGuard !== null && isset($config['url']) && is_string($config['url']) && $config['url'] !== '') {
            $this->urlGuard->assertAllowed($config['url'], 'mcp:' . $serverName);
        }
    }

    /** @return array<string, mixed> */
    private function resolveConfig(string $name, ?string $tenantId): array
    {
        if ($this->repository !== null && $tenantId !== null && $tenantId !== '') {
            $row = $this->repository->find($name, $tenantId);
            if ($row !== null && $row->enabled) {
                return $row->config;
            }
        }

        if ($this->repository !== null) {
            $global = $this->repository->find($name, null);
            if ($global !== null && $global->enabled) {
                return $global->config;
            }
        }

        if (isset($this->servers[$name]) && is_array($this->servers[$name])) {
            return $this->servers[$name];
        }

        return [];
    }
}
