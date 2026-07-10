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

namespace Swoolefy\Support\Workflow\Condition;

use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Phase 1 默认条件求值器 —— Symfony ExpressionLanguage。
 *
 * 注入表达式的只读上下文：
 *   data         — state.data 简写
 *   nodeOutputs  — 各节点输出
 *   feedback     — HITL resume 后的 state.data.feedback
 *   state        — 完整 WorkflowState
 *
 * 示例：data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8
 */
final class SymfonyExpressionLanguageEvaluator implements ConditionEvaluatorInterface
{
    private ExpressionLanguage $language;

    public function __construct(?ExpressionLanguage $language = null)
    {
        $this->language = $language ?? new ExpressionLanguage();
    }

    /** {@inheritdoc} */
    public function evaluate(EdgeCondition $condition, WorkflowState $state): bool
    {
        return match ($condition->type) {
            EdgeCondition::TYPE_ALWAYS => true,
            EdgeCondition::TYPE_CALLABLE => (bool) ($condition->callable)($state),
            EdgeCondition::TYPE_EXPRESSION => (bool) $this->language->evaluate(
                (string) $condition->expression,
                $this->buildContext($state),
            ),
            default => throw new WorkflowException("Unsupported condition type {$condition->type}"),
        };
    }

    /** 构建 Symfony EL 求值上下文。 */
    private function buildContext(WorkflowState $state): array
    {
        return [
            'data' => $state->data,
            'nodeOutputs' => $state->nodeOutputs,
            'agentOutputs' => $state->agentOutputs,
            'feedback' => $state->get('feedback'),
            'state' => $state,
        ];
    }
}
