<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Condition;

use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Callable 专用条件求值器 —— 仅处理 EdgeCondition::fromCallable()。
 *
 * 可与 {@see SymfonyExpressionLanguageEvaluator} 组合为 {@see CompositeConditionEvaluator}。
 */
final class CallableConditionEvaluator implements ConditionEvaluatorInterface
{
    /** {@inheritdoc} */
    public function evaluate(EdgeCondition $condition, WorkflowState $state): bool
    {
        return match ($condition->type) {
            EdgeCondition::TYPE_ALWAYS => true,
            EdgeCondition::TYPE_CALLABLE => (bool) ($condition->callable)($state),
            default => throw new WorkflowException(
                "CallableConditionEvaluator cannot evaluate condition type {$condition->type}",
            ),
        };
    }
}
