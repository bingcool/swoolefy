<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter\Resolver;

use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;
use Swoolefy\Support\CapabilityCenter\CapabilitySource;

/**
 * 确定性策略过滤器（Policy 阶段）。
 *
 * Phase 3 有意保持规则简单、可预测，不做复杂 RBAC：
 * - disabled descriptor 直接过滤；
 * - 带 tenant 的 descriptor 必须与当前 tenant 匹配；
 * - requiredRoles 必须全部满足（array_diff 非空则过滤）；
 * - critical 风险工具在 HITL / ToolExecutor 落地前默认过滤；
 * - MCP Tool：ctx.mcpServers 为空则全部过滤；非空时只能来自白名单 server；
 * - mcpOnly / mcpExclude 下沉自 NeuronFactory 原有静态过滤逻辑。
 */
final class PolicyToolFilter
{
    /**
     * 对 descriptor 列表做确定性策略过滤。
     *
     * @param list<CapabilityDescriptor> $descriptors Registry 全量或子集
     * @param ToolResolveContext         $context     当前请求上下文
     *
     * @return list<CapabilityDescriptor> 通过策略的 descriptor
     */
    public function filter(array $descriptors, ToolResolveContext $context): array
    {
        $items = [];
        foreach ($descriptors as $descriptor) {
            // 类型校验 + enabled 开关
            if (!$descriptor instanceof CapabilityDescriptor || !$descriptor->enabled) {
                continue;
            }

            // 租户隔离：descriptor 有 tenantId 时必须与 ctx 一致
            if ($descriptor->tenantId !== null && $descriptor->tenantId !== $context->tenantId) {
                continue;
            }

            // 角色校验：requiredRoles 须全部被 ctx.roles 包含
            if ($descriptor->requiredRoles !== [] && array_diff($descriptor->requiredRoles, $context->roles) !== []) {
                continue;
            }

            // critical 工具 Phase 3 默认拒绝，待 HITL / ToolExecutor 接入
            if ($descriptor->riskLevel === 'critical') {
                continue;
            }

            // MCP 来源：未声明 mcpServers 时一律过滤（对齐 attachMcpTools 空列表不挂载）；
            // 已声明时仅放行白名单 server。
            if ($descriptor->source === CapabilitySource::Mcp) {
                if ($context->mcpServers === []) {
                    continue;
                }
                if ($descriptor->mcpServer === null || !in_array($descriptor->mcpServer, $context->mcpServers, true)) {
                    continue;
                }
            }

            // MCP 静态 only / exclude（对应原 mcpOnly / mcpExclude nodeConfig）
            if ($descriptor->source === CapabilitySource::Mcp && $descriptor->mcpServer !== null) {
                $only = $context->mcpOnly[$descriptor->mcpServer] ?? null;
                if (is_array($only) && $only !== [] && !in_array($descriptor->name, $only, true)) {
                    continue;
                }
                $exclude = $context->mcpExclude[$descriptor->mcpServer] ?? null;
                if (is_array($exclude) && in_array($descriptor->name, $exclude, true)) {
                    continue;
                }
            }

            $items[] = $descriptor;
        }

        return $items;
    }
}
