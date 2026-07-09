<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter\Resolver;

/**
 * 单次请求 / 单轮 Agent 调用的工具解析上下文。
 *
 * 该对象属于请求态，禁止放入 static 或全局缓存。
 * query、tenant、roles、pinned tools、MCP 来源限制等运行时信息都必须通过它传递。
 *
 * 字段来源参考：
 * - query          ← 用户消息 / Workflow state / agentOptions
 * - agentId        ← Agent class 或 node id
 * - tenantId       ← agentOptions → FrameworkContext::getTenantId()
 * - roles          ← agentOptions / header / 登录态
 * - mcpServers     ← agentOptions['mcpServers'] 或 agentOptions['mcp']；空则过滤全部 MCP Tool
 * - pinnedToolIds  ← agentOptions / Agent 配置
 * - topK           ← agentOptions → CAPABILITY_DEFAULT_TOP_K
 */
final class ToolResolveContext
{
    /**
     * @param string                        $query             当前用户消息或 prompt，供 tag 匹配
     * @param string                        $agentId           Agent 标识，用于调试日志
     * @param string|null                   $tenantId          当前租户
     * @param string|null                   $userId            当前用户
     * @param list<string>                  $roles             当前用户角色列表
     * @param list<string>                  $pinnedToolIds     必须注入的 descriptor ID（不占 topK）
     * @param list<string>                  $mcpServers        MCP 白名单；空数组表示不放行任何 MCP Tool
     * @param string|null                   $capabilityProfile 场景 profile 名，参与 tag 打分
     * @param list<string>                  $profileTags       profile 关联标签
     * @param int                           $topK              普通动态候选上限
     * @param array<string, list<string>>   $mcpOnly           按 server 静态白名单 tool 名
     * @param array<string, list<string>>   $mcpExclude        按 server 静态排除 tool 名
     */
    public function __construct(
        public readonly string $query,
        public readonly string $agentId,
        public readonly ?string $tenantId,
        public readonly ?string $userId,
        public readonly array $roles = [],
        public readonly array $pinnedToolIds = [],
        public readonly array $mcpServers = [],
        public readonly ?string $capabilityProfile = null,
        public readonly array $profileTags = [],
        public readonly int $topK = 12,
        public readonly array $mcpOnly = [],
        public readonly array $mcpExclude = [],
    ) {
    }
}
