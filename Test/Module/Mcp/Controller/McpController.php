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
 *   GET /api/v1/mcp/servers/{id}/tools?tenantId=
 *
 * 多租户：
 *   Query tenantId 优先；未传时使用 FrameworkContext::getTenantId()。
 *   McpFactory 解析顺序：DB 租户行 → DB 全局行 → neuron_ai.php 静态 servers。
 *   DB 仓储须预执行 Schema/mcp_server_configs.sql。
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
            'servers' => WorkflowService::mcpFactory()->listServers($tenantId),
        ];
    }

    /**
     * 发现指定 Server 的工具名列表。
     *
     * GET /api/v1/mcp/servers/{id}/tools?tenantId=
     */
    public function tools(RequestInput $requestInput): array
    {
        $serverId = (string) $requestInput->input('id', '');
        if ($serverId === '') {
            return $this->returnJson([], 400, 'id is required');
        }

        $tenantId = $this->resolveTenantId($requestInput);

        return [
            'serverId' => $serverId,
            'tenantId' => $tenantId,
            'tools' => WorkflowService::mcpFactory()->listToolNames($serverId, $tenantId),
        ];
    }

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
