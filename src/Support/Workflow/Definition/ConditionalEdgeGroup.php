<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

/**
 * 同源多分支条件边组。
 * 由 WorkflowDefinition::addConditionalEdges() 创建。
 */
final class ConditionalEdgeGroup
{
    /**
     * @param array<string, EdgeCondition> $branches 目标节点 id => 条件
     * @param string|null                  $default  无匹配时的兜底目标
     */
    public function __construct(
        public readonly string $from,
        public readonly array $branches,
        public readonly ?string $default = null,
    ) {
    }
}
