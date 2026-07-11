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
     * 流程：resolve → 逐个 materialize → maxSchemaTools 截断（仅截断非 pinned）。
     * pinned 优先保留：不因 matched 占满预算而被丢弃；仅当 pinned 自身超过上限时截断 pinned。
     * 单个 Tool materialize 失败时 Materializer 返回 null 并记 warning，不中断整体。
     *
     * @return list<ToolInterface>
     */
    public function resolveTools(ToolResolveContext $context): array
    {
        // 第一阶段：Resolver 输出带分数的候选列表（含 pinned）
        $resolved = $this->resolver->resolve($context);
        /** @var list<ToolInterface> $pinnedTools */
        $pinnedTools = [];
        /** @var list<ToolInterface> $matchedTools */
        $matchedTools = [];

        foreach ($resolved as $item) {
            // 第二阶段：按 descriptor 懒加载真实 Tool；pinned 失败时日志更明显
            $tool = $this->materializer->materialize($item->descriptor, $item->stage === 'pinned');
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            if ($item->stage === 'pinned') {
                $pinnedTools[] = $tool;
            } else {
                $matchedTools[] = $tool;
            }
        }

        // pinned 优先占满预算，剩余名额再给 matched（与 Resolver「pinned 不占 topK」语义对齐）
        $max = max(0, $this->maxSchemaTools);
        $pinnedKept = array_slice($pinnedTools, 0, $max);
        $remaining = max(0, $max - count($pinnedKept));
        $tools = [...$pinnedKept, ...array_slice($matchedTools, 0, $remaining)];

        if (count($pinnedTools) > $max) {
            SupportLog::warning('capability', 'capability.pinned_truncated', [
                'agentId' => $context->agentId,
                'pinned' => count($pinnedTools),
                'maxSchemaTools' => $max,
                'kept' => count($pinnedKept),
            ]);
        }

        // 调试模式：记录解析与 materialize 汇总信息
        if ($this->debug) {
            SupportLog::debug('capability', 'capability.resolve', [
                'agentId' => $context->agentId,
                'selected' => count($tools),
                'pinned' => count($pinnedKept),
                'topK' => $context->topK,
            ]);
        }

        return $tools;
    }
}
