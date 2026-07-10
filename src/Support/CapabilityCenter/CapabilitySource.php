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

namespace Swoolefy\Support\CapabilityCenter;

/**
 * Capability 来源类型枚举。
 *
 * 用于 CapabilityDescriptor::source 字段，标识该工具元数据来自何处，
 * 并决定 LazyToolMaterializer 采用哪种实例化策略。
 *
 * Phase 3 只实际落地 MCP 与 Native 两类：
 * - MCP：由 McpFactory 同步远端 server 的 tool 元数据，命中后再懒加载真实 Tool。
 * - Native：由业务 / 测试显式注册本地 Tool 工厂，适合 RAG 检索等核心工具。
 *
 * 其它来源先作为元数据模型预留，后续可扩展 API / DB / Workflow Tool。
 */
enum CapabilitySource: string
{
    /** MCP Server 远端工具；ID 规则 mcp:{serverName}:{toolName}。 */
    case Mcp = 'mcp';

    /** 本地显式注册的 Native Tool；适合 RAG 检索等 pinned 核心工具。 */
    case Native = 'native';

    /** OpenAPI / HTTP API 工具（Phase 5 预留）。 */
    case Api = 'api';

    /** 数据库 Toolkit 工具（Phase 5 预留）。 */
    case Db = 'db';

    /** Workflow 子流程作为 Tool（Phase 5 预留）。 */
    case Workflow = 'workflow';
}
