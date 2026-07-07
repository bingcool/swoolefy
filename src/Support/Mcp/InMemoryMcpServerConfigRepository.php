<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

/**
 * 内存 MCP 配置仓储 —— 单测与 HTTP 演示；生产替换为 DB 实现。
 *
 * 按 server_id 唯一索引；与生产表 mcp_server_configs 语义一致。
 */
final class InMemoryMcpServerConfigRepository implements McpServerConfigRepositoryInterface
{
    /** @var array<string, McpServerConfig> */
    private array $configs = [];

    /** 插入或覆盖一条配置。 */
    public function upsert(McpServerConfig $config): void
    {
        $this->configs[$config->server_id] = $config;
    }

    /** {@inheritdoc} */
    public function list(): array
    {
        $items = [];
        foreach ($this->configs as $config) {
            if (!$config->enabled) {
                continue;
            }
            $items[] = $config;
        }

        return array_values($items);
    }

    /** {@inheritdoc} */
    public function find(string $server_id): ?McpServerConfig
    {
        return $this->configs[$server_id] ?? null;
    }
}
