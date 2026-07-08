<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter;

use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolveContext;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolverInterface;
use Swoolefy\Support\SupportLog;

/**
 * 运行时工具解析门面。
 *
 * CapabilityCenter 不负责执行 Tool，只在 Agent::addTool() 之前完成：
 * 1. 通过 Resolver 解析出少量候选 Capability（ResolvedCapability[]）；
 * 2. 通过 Materializer 将候选懒加载为 Neuron ToolInterface；
 * 3. 返回最终工具列表供 NeuronFactory 注入 Agent。
 */
final class CapabilityCenter
{
    /**
     * @param ToolResolverInterface $resolver       解析流水线（policy + tag + pinned）
     * @param LazyToolMaterializer  $materializer   descriptor → ToolInterface 懒加载
     * @param int                   $maxSchemaTools 注入 LLM schema 的最大工具数兜底
     * @param bool                  $debug          是否输出 capability.resolve 调试日志
     */
    public function __construct(
        private readonly ToolResolverInterface $resolver,
        private readonly LazyToolMaterializer $materializer,
        private readonly int $maxSchemaTools = 20,
        private readonly bool $debug = false,
    ) {
    }

    /**
     * 解析并 materialize 工具，返回可注入 Agent 的 ToolInterface 列表。
     *
     * 流程：resolve → 逐个 materialize → maxSchemaTools 截断。
     * 单个 Tool materialize 失败时 Materializer 返回 null 并记 warning，不中断整体。
     *
     * @return list<ToolInterface>
     */
    public function resolveTools(ToolResolveContext $context): array
    {
        $startedAt = microtime(true);

        // 第一阶段：Resolver 输出带分数的候选列表（含 pinned）
        $resolved = $this->resolver->resolve($context);
        $tools = [];

        foreach ($resolved as $item) {
            // 第二阶段：按 descriptor 懒加载真实 Tool；pinned 失败时日志更明显
            $tool = $this->materializer->materialize($item->descriptor, $item->stage === 'pinned');
            if ($tool instanceof ToolInterface) {
                $tools[] = $tool;
            }

            // 兜底截断：即使 Resolver 选出更多，也不超过 maxSchemaTools
            if (count($tools) >= $this->maxSchemaTools) {
                break;
            }
        }

        // 调试模式：记录解析与 materialize 汇总信息
        if ($this->debug) {
            SupportLog::info('capability', 'capability.resolve', [
                'agentId' => $context->agentId,
                'selected' => count($tools),
                'resolved' => count($resolved),
                'topK' => $context->topK,
                'profile' => $context->capabilityProfile,
                'tenantId' => $context->tenantId,
                'latencyMs' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $tools;
    }
}
