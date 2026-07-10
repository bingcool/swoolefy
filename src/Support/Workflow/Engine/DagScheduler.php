<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * DAG 调度器 —— 节点 SUCCESS 后解析下一跳。
 *
 * 路由优先级（同一源节点）：
 *   1. ConditionalEdgeGroup（addConditionalEdges）— 按声明顺序求值，首个 true 获胜
 *   2. 固定边（addEdge）
 *   3. null — 路径结束
 *
 * 仅在上一节点返回 SUCCESS 时求值；WAITING / FAILED 不触发路由。
 *
 * @see docs/SwoolefyAI.md §6.1、§4.9.3
 */
final class DagScheduler
{
    public function __construct(
        private readonly ConditionEvaluatorInterface $conditionEvaluator,
    ) {
    }

    /**
     * 解析下一节点 id。
     *
     * @return string|null 下一节点 id，null 表示 DAG 路径结束
     */
    public function resolveNextNode(
        CompiledWorkflow $compiled,
        string $fromNode,
        WorkflowState $state,
    ): ?string {
        $group = $compiled->conditionalGroup($fromNode);
        if ($group !== null) {
            return $this->resolveConditionalGroup($group, $state);
        }

        return $compiled->fixedEdge($fromNode);
    }

    /** 按分支顺序求值条件边组，无匹配时使用 default。 */
    private function resolveConditionalGroup(
        \Swoolefy\Support\Workflow\Definition\ConditionalEdgeGroup $group,
        WorkflowState $state,
    ): ?string {
        foreach ($group->branches as $target => $condition) {
            if ($this->conditionEvaluator->evaluate($condition, $state)) {
                return $target;
            }
        }

        if ($group->default !== null) {
            return $group->default;
        }

        throw new WorkflowException("No matching conditional edge from {$group->from}");
    }
}
