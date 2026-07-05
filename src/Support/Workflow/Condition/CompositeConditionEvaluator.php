<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Condition;

use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 组合条件求值器 —— 按 EdgeCondition.type 路由到 Symfony / Callable / JsonLogic。
 */
final class CompositeConditionEvaluator implements ConditionEvaluatorInterface
{
    public function __construct(
        private readonly SymfonyExpressionLanguageEvaluator $symfony = new SymfonyExpressionLanguageEvaluator(),
        private readonly CallableConditionEvaluator $callable = new CallableConditionEvaluator(),
        private readonly JsonLogicEvaluator $jsonLogic = new JsonLogicEvaluator(),
    ) {
    }

    /** {@inheritdoc} */
    public function evaluate(EdgeCondition $condition, WorkflowState $state): bool
    {
        return match ($condition->type) {
            EdgeCondition::TYPE_JSON_LOGIC => $this->jsonLogic->evaluate($condition, $state),
            EdgeCondition::TYPE_CALLABLE, EdgeCondition::TYPE_ALWAYS => $this->callable->evaluate($condition, $state),
            default => $this->symfony->evaluate($condition, $state),
        };
    }
}
