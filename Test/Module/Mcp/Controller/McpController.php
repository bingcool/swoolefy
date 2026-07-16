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
 * 路由定义：`Test/Router/Module/Mcp.php`（前缀 `api`，默认端口 9501）。
 * 各方法 PHPDoc 中 curl 代码块无行首 `*`，便于直接复制执行。
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
     * Route: GET /api/v1/mcp/servers?tenantId=
     *
     ```bash
     # 列出全部 Server（凭证脱敏）
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers' \
       -H 'Accept: application/json'

     # Query tenantId（仅回显，不参与配置解析）
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers?tenantId=tenant_demo' \
       -H 'Accept: application/json'

     # Header 透传租户（无 Query 时走 FrameworkContext）
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers' \
       -H 'Accept: application/json' \
       -H 'x-tenant-id: tenant_demo'
     ```
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
     * Route: GET /api/v1/mcp/servers/tools?server_id=&tenantId=
     *
     * Demo server_id：`demo_http`（InMemory stub，tools 可能为空）。
     *
     ```bash
     # 按 server_id 发现 tools
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers/tools?server_id=demo_http' \
       -H 'Accept: application/json'

     # 带 tenantId 回显
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers/tools?server_id=demo_http&tenantId=tenant_demo' \
       -H 'Accept: application/json'

     # Header 透传租户
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers/tools?server_id=demo_http' \
       -H 'Accept: application/json' \
       -H 'x-tenant-id: tenant_demo'

     # 缺少 server_id → 400
     curl -X GET 'http://127.0.0.1:9501/api/v1/mcp/servers/tools' \
       -H 'Accept: application/json'
     ```
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
