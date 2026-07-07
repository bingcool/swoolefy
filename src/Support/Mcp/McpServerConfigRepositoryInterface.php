<?php

declare(strict_types=1);

namespace Swoolefy\Support\Mcp;

/**
 * MCP Server 配置仓储接口。
 *
 * 生产 DB 实现读取 mcp_server_configs 全局基础配置表（server_id 唯一）。
 * 演示实现：{@see InMemoryMcpServerConfigRepository}
 */
interface McpServerConfigRepositoryInterface
{
    /**
     * 列出可用 Server 配置（enabled=1 且未软删）。
     *
     * @return list<McpServerConfig>
     */
    public function list(): array;

    /** 按 server_id 查找配置。 */
    public function find(string $server_id): ?McpServerConfig;
}
