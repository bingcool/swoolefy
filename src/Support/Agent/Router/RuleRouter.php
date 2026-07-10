<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 规则路由 —— 按表达式 / callable 从 WorkflowState 选择 Agent。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   根据业务字段动态决定跑哪些 Agent，例如：
 *   - amount > 1000 → 启用 finance Agent
 *   - 含代码关键词 → 启用 coding Agent
 *
 * 规则求值语义与条件边一致：可复用 {@see EdgeCondition} + Symfony EL Evaluator，
 * 或直接传 `callable(WorkflowState): bool`。
 *
 * ---------------------------------------------------------------------------
 * 行为
 * ---------------------------------------------------------------------------
 *
 *   - 遍历 rules：agentId => 条件；条件为 true 则加入结果（可多选，顺序=声明顺序）
 *   - 全部不匹配 → 返回空列表（Scheduler 将无任务可跑；调用方应保证有兜底规则或 default Agent）
 *   - EdgeCondition 且未注入 evaluator → 该条视为不匹配（false），避免静默求值失败
 *
 * 示例：
 *
 *   new RuleRouter([
 *       'finance' => EdgeCondition::when('amount > 1000'),
 *       'coding'  => fn (WorkflowState $s) => str_contains((string)$s->get('query'), 'api'),
 *   ], $symfonyEvaluator);
 *
 * @see ConditionEvaluatorInterface
 * @see EdgeCondition
 */
final class RuleRouter implements AgentRouterInterface
{
    /**
     * @param array<string, EdgeCondition|callable(WorkflowState): bool> $rules
     *        agentId => 是否选中该 Agent 的条件
     * @param ConditionEvaluatorInterface|null $evaluator
     *        求值 EdgeCondition 时必需；仅使用 callable 规则时可传 null
     */
    public function __construct(
        private readonly array $rules,
        private readonly ?ConditionEvaluatorInterface $evaluator = null,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string> 本轮命中的 agentId（可为空、可多个）
     */
    public function route(RouterContext $ctx): array
    {
        $selected = [];

        // 声明顺序即并行启动顺序的稳定参考（Scheduler 仍可能协程并发）。
        foreach ($this->rules as $agentId => $rule) {
            if ($this->matches($rule, $ctx->state)) {
                $selected[] = $agentId;
            }
        }

        return $selected;
    }

    /**
     * 单条规则是否命中。
     *
     * EdgeCondition → 必须经 Evaluator（与 DagScheduler 条件边同一套引擎）；
     * callable → 直接读 state，适合简单 PHP 判断。
     */
    private function matches(EdgeCondition|callable $rule, WorkflowState $state): bool
    {
        if ($rule instanceof EdgeCondition) {
            // 无求值器时不能猜表达式真假，保守返回 false。
            if ($this->evaluator === null) {
                return false;
            }

            return $this->evaluator->evaluate($rule, $state);
        }

        return (bool) $rule($state);
    }
}
