<?php

declare(strict_types=1);

namespace Test\Module\Mcp\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Test\Module\Workflow\WorkflowService;

/**
 * MCP 管理 HTTP API（Phase 4）。
 *
 * 职责：
 *   - 列出已配置 MCP Server（凭证脱敏）
 *   - 按 Server 发现 tools/list（运维调试）
 *
 * 路由：
 *   GET /api/v1/mcp/servers?tenantId=
 *   GET /api/v1/mcp/servers/{id}/tools?tenantId=
 *
 * 多租户：tenantId 传给 McpFactory → Repository 过滤
 */
final class McpController extends BController
{
    /**
     * 列出 MCP Server 公开信息。
     *
     * Query: tenantId（可选）— 多租户隔离
     */
    public function servers(RequestInput $requestInput): array
    {
        $tenantId = $requestInput->input('tenantId');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        return $this->returnJson([
            'servers' => WorkflowService::mcpFactory()->listServers($tenantId),
        ]);
    }

    /**
     * 发现指定 Server 的工具名列表。
     *
     * Path: id — Server 名称
     * Query: tenantId（可选）
     */
    public function tools(RequestInput $requestInput): array
    {
        $serverId = (string) $requestInput->input('id', '');
        if ($serverId === '') {
            return $this->returnJson([], 400, 'id is required');
        }

        $tenantId = $requestInput->input('tenantId');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        return $this->returnJson([
            'serverId' => $serverId,
            'tools' => WorkflowService::mcpFactory()->listToolNames($serverId, $tenantId),
        ]);
    }
}
