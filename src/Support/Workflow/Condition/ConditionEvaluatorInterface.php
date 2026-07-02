<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Condition;

use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 可插拔条件求值器接口。
 *
 * Phase 1 默认：{@see SymfonyExpressionLanguageEvaluator}
 * Phase 2+：JsonLogicEvaluator、CelEvaluator 等
 *
 * @see swoolefyAI.md §4.9.2
 */
interface ConditionEvaluatorInterface
{
    /**
     * 对 EdgeCondition 求值。
     *
     * @return bool 是否满足条件（true 则选中对应分支）
     */
    public function evaluate(EdgeCondition $condition, WorkflowState $state): bool;
}
