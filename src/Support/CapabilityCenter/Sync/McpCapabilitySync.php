<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter\Sync;

use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;
use Swoolefy\Support\CapabilityCenter\CapabilityRegistryInterface;
use Swoolefy\Support\CapabilityCenter\CapabilitySource;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * 将 MCP Tool 元数据同步到 Capability Registry。
 *
 * 职责边界：
 * - 只同步轻量 descriptor（name / description / tags），不持有 connector 或真实 Tool；
 * - 通过 McpFactory::listToolNames() 发现 tool 名称；
 * - 单个 server 失败只 warning，不清空 Registry 已有内容。
 */
final class McpCapabilitySync
{
    /**
     * @param McpFactory                   $mcpFactory MCP 配置与安全链
     * @param CapabilityRegistryInterface $registry   目标注册表（通常 InMemoryCapabilityRegistry）
     */
    public function __construct(
        private readonly McpFactory $mcpFactory,
        private readonly CapabilityRegistryInterface $registry,
    ) {
    }

    /**
     * 同步单个 MCP Server 的 tool 元数据到 Registry。
     *
     * @param string      $serverName MCP server 名
     * @param string|null $tenantId   写入 descriptor.tenantId（可选）
     *
     * @return int 成功同步的 descriptor 数量；失败返回 0
     */
    public function syncServer(string $serverName, ?string $tenantId = null): int
    {
        $startedAt = microtime(true);
        try {
            $descriptors = [];

            // 通过 McpFactory 获取 tool 名称列表（会走安全链，但不 materialize 全量 Tool）
            foreach ($this->mcpFactory->listToolNames($serverName) as $toolName) {
                $descriptors[] = new CapabilityDescriptor(
                    id: self::mcpCapabilityId($serverName, $toolName),
                    name: $toolName,
                    // Phase 3：拿不到 MCP description 时用保守 fallback
                    description: sprintf('MCP tool [%s] from server [%s].', $toolName, $serverName),
                    source: CapabilitySource::Mcp,
                    tags: $this->tags($serverName, $toolName),
                    tenantId: $tenantId,
                    executorRef: 'mcp:' . $serverName,
                    mcpServer: $serverName,
                );
            }

            // 批量写入 Registry（同 id 会覆盖，用于 refresh）
            $this->registry->registerBatch($descriptors);
            SupportLog::info('capability', 'capability.registry.sync', [
                'source' => CapabilitySource::Mcp->value,
                'serverName' => $serverName,
                'count' => count($descriptors),
                'tenantId' => $tenantId,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return count($descriptors);
        } catch (Throwable $e) {
            // 单 server 失败不抛异常，保留其它 server 已有 descriptor
            SupportLog::warning('capability', 'Failed to sync MCP capability metadata', [
                'serverName' => $serverName,
                'tenantId' => $tenantId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return 0;
        }
    }

    /**
     * 批量同步多个 MCP Server。
     *
     * @param list<string> $serverNames server 名列表
     * @param string|null  $tenantId    租户上下文
     *
     * @return int 所有 server 同步成功的 descriptor 总数
     */
    public function syncServers(array $serverNames, ?string $tenantId = null): int
    {
        $count = 0;
        foreach ($serverNames as $serverName) {
            if (is_string($serverName) && $serverName !== '') {
                $count += $this->syncServer($serverName, $tenantId);
            }
        }

        return $count;
    }

    /**
     * 生成 MCP Capability 的标准 ID。
     *
     * 格式：mcp:{serverName}:{toolName}
     */
    public static function mcpCapabilityId(string $serverName, string $toolName): string
    {
        return 'mcp:' . $serverName . ':' . $toolName;
    }

    /**
     * 从 server 名与 tool 名生成分词 tags，供 TagToolMatcher 匹配。
     *
     * 包含原始 server/tool 名，以及对 tool 名下划线分词后的 token。
     *
     * @return list<string>
     */
    private function tags(string $serverName, string $toolName): array
    {
        $tags = [$serverName, $toolName];

        // 对 server + tool 名做分词，tool 名下划线替换为空格便于拆分
        foreach (preg_split('/[^a-zA-Z0-9_-]+/', $serverName . ' ' . str_replace('_', ' ', $toolName), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = strtolower($part);
            if ($part !== '' && !in_array($part, $tags, true)) {
                $tags[] = $part;
            }
        }

        return array_values($tags);
    }
}
