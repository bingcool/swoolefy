<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 规则路由 —— 按 Symfony EL / callable 从 WorkflowState 选择 Agent。
 */
final class RuleRouter implements AgentRouterInterface
{
    /**
     * @param array<string, EdgeCondition|callable(WorkflowState): bool> $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly ?ConditionEvaluatorInterface $evaluator = null,
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        $selected = [];

        foreach ($this->rules as $agentId => $rule) {
            if ($this->matches($rule, $ctx->state)) {
                $selected[] = $agentId;
            }
        }

        return $selected;
    }

    private function matches(EdgeCondition|callable $rule, WorkflowState $state): bool
    {
        if ($rule instanceof EdgeCondition) {
            if ($this->evaluator === null) {
                return false;
            }

            return $this->evaluator->evaluate($rule, $state);
        }

        return (bool) $rule($state);
    }
}
