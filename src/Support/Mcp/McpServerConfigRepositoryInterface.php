<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

/**
 * MCP Server 配置仓储接口 —— 多租户隔离查询。
 *
 * 生产实现：读 mcp_server_configs 表，按 tenant_id + server_id 唯一约束。
 * 演示实现：{@see InMemoryMcpServerConfigRepository}
 */
interface McpServerConfigRepositoryInterface
{
    /**
     * 列出可用 Server 配置。
     *
     * @param string|null $tenantId 传入时仅返回该租户 + 全局（tenantId=null）配置
     *
     * @return list<McpServerConfig>
     */
    public function list(?string $tenantId = null): array;

    /**
     * 按 ID 查找配置。
     *
     * @param string      $id       Server ID
     * @param string|null $tenantId 租户上下文
     */
    public function find(string $id, ?string $tenantId = null): ?McpServerConfig;
}
