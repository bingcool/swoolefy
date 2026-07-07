<?php

declare(strict_types=1);

namespace Test\Module\Mcp\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\FrameworkContext;
use Test\Module\Workflow\WorkflowService;

/**
 * MCP 管理 HTTP API（Phase 4 + Phase B 多租户）。
 *
 * 职责：
 *   - 列出已配置 MCP Server（凭证脱敏）
 *   - 按 Server 发现 tools/list（运维调试）
 *
 * 路由：
 *   GET /api/v1/mcp/servers?tenantId=
 *   GET /api/v1/mcp/servers/tools?server_id=&tenantId=
 *
 * MCP 配置为全局基础配置（mcp_server_configs.server_id 唯一）；
 * Query tenantId 仅回显请求上下文，不参与 MCP 配置解析。
 * DB 仓储须预执行 Schema/mcp_server_configs.sql。
 *
 * 安全（生产）：
 *   stdio MCP 默认禁用（MCP_ALLOW_STDIO=0）；出站 url 受 security.outbound_url_allowlist 约束。
 *
 * @see \Swoolefy\Support\Mcp\README.md
 */
final class McpController extends BController
{
    /**
     * 列出 MCP Server 公开信息。
     *
     * GET /api/v1/mcp/servers?tenantId=
     */
    public function servers(RequestInput $requestInput): array
    {
        $tenantId = $this->resolveTenantId($requestInput);

        return [
            'tenantId' => $tenantId,
            'servers' => WorkflowService::mcpFactory()->listServers(),
        ];
    }

    /**
     * 发现指定 Server 的工具名列表。
     *
     * GET /api/v1/mcp/servers/tools?server_id=&tenantId=
     */
    public function tools(RequestInput $requestInput): array
    {
        $serverId = (string) $requestInput->input('server_id', '');
        if ($serverId === '') {
            return $this->returnJson([], 400, 'server_id is required');
        }

        $tenantId = $this->resolveTenantId($requestInput);

        return [
            'server_id' => $serverId,
            'tenantId' => $tenantId,
            'tools' => WorkflowService::mcpFactory()->listToolNames($serverId),
        ];
    }

    /**
     * 解析租户 ID：Query tenantId 优先，否则 FrameworkContext（Header 透传）。
     *
     * 与 NeuronFactory::attachMcpTools() 的 tenantId 解析策略一致。
     */
    private function resolveTenantId(RequestInput $requestInput): ?string
    {
        $tenantId = $requestInput->input('tenantId');
        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }

        $fromContext = FrameworkContext::getTenantId();

        return is_string($fromContext) && $fromContext !== '' ? $fromContext : null;
    }
}
