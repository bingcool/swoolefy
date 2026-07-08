<?php

declare(strict_types=1);

namespace Swoolefy\Support\CapabilityCenter\Resolver;

use Swoolefy\Support\CapabilityCenter\CapabilityRegistryInterface;

/**
 * 默认解析流水线：registry → policy filter → tag matcher → pinned merge。
 *
 * 完整流程：
 * 1. 从 Registry 取全量 descriptor；
 * 2. PolicyToolFilter 做确定性过滤（tenant / role / risk / mcpServers / only / exclude）；
 * 3. 从通过 Policy 的集合中提取 pinned（仍须过 Policy，但不占 topK）；
 * 4. TagToolMatcher 对剩余候选打分排序，截断 topK；
 * 5. 合并 [pinned..., matched...] 返回。
 */
final class CompositeToolResolver implements ToolResolverInterface
{
    /**
     * @param CapabilityRegistryInterface $registry     元数据来源
     * @param PolicyToolFilter            $policyFilter   确定性策略过滤
     * @param TagToolMatcher              $tagMatcher     轻量 tag/query 打分
     */
    public function __construct(
        private readonly CapabilityRegistryInterface $registry,
        private readonly PolicyToolFilter $policyFilter = new PolicyToolFilter(),
        private readonly TagToolMatcher $tagMatcher = new TagToolMatcher(),
    ) {
    }

    /**
     * 执行完整解析流水线。
     *
     * @return list<ResolvedCapability> pinned 在前（score=1000），tag 匹配在后
     */
    public function resolve(ToolResolveContext $context): array
    {
        // 第一步：Policy 确定性过滤
        $allowed = $this->policyFilter->filter($this->registry->all(), $context);

        // 建立 id → descriptor 索引，供 pinned 快速查找
        $allowedById = [];
        foreach ($allowed as $descriptor) {
            $allowedById[$descriptor->id] = $descriptor;
        }

        // 第二步：提取 pinned tools（须已通过 Policy，但不占 topK quota）
        $pinned = [];
        foreach ($context->pinnedToolIds as $id) {
            if (isset($allowedById[$id])) {
                $pinned[$id] = new ResolvedCapability($allowedById[$id], 1000.0, 'pinned');
            }
        }

        // 第三步：Tag 匹配 + topK 截断（跳过已在 pinned 中的 id）
        $matched = [];
        foreach ($this->tagMatcher->match($allowed, $context) as $resolved) {
            if (isset($pinned[$resolved->descriptor->id])) {
                continue;
            }
            $matched[] = $resolved;
            if (count($matched) >= max(0, $context->topK)) {
                break;
            }
        }

        // pinned 始终排在前面，保证核心工具优先 materialize
        return array_values([...$pinned, ...$matched]);
    }
}
