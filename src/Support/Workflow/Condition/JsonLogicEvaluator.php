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

/**
 * JSON Logic 条件求值器（Phase 2+ 子集实现，无第三方依赖）。
 *
 * 支持算子：==、!=、>、>=、<、<=、and、or、!、var
 * var 示例：{"var": "data.decision.approved"} 或 {"var": ["data", "decision", "approved"]}
 *
 * @see EdgeCondition::fromJsonLogic()
 */
final class JsonLogicEvaluator implements ConditionEvaluatorInterface
{
    /** {@inheritdoc} */
    public function evaluate(EdgeCondition $condition, WorkflowState $state): bool
    {
        if ($condition->type !== EdgeCondition::TYPE_JSON_LOGIC) {
            throw new WorkflowException(
                "JsonLogicEvaluator only supports jsonlogic type, got {$condition->type}",
            );
        }

        return (bool) $this->apply($condition->jsonLogic ?? [], $state);
    }

    /** @param array<string, mixed> $rule */
    private function apply(array $rule, WorkflowState $state): mixed
    {
        if ($rule === []) {
            return false;
        }

        $operator = array_key_first($rule);
        $operands = $rule[$operator];

        return match ($operator) {
            '==' => $this->resolve($operands[0] ?? null, $state) == $this->resolve($operands[1] ?? null, $state),
            '!=' => $this->resolve($operands[0] ?? null, $state) != $this->resolve($operands[1] ?? null, $state),
            '>' => $this->resolve($operands[0] ?? null, $state) > $this->resolve($operands[1] ?? null, $state),
            '>=' => $this->resolve($operands[0] ?? null, $state) >= $this->resolve($operands[1] ?? null, $state),
            '<' => $this->resolve($operands[0] ?? null, $state) < $this->resolve($operands[1] ?? null, $state),
            '<=' => $this->resolve($operands[0] ?? null, $state) <= $this->resolve($operands[1] ?? null, $state),
            'and' => $this->allTrue(is_array($operands) ? $operands : [$operands], $state),
            'or' => $this->anyTrue(is_array($operands) ? $operands : [$operands], $state),
            '!' => !$this->apply(is_array($operands) ? $operands : [], $state),
            'var' => $this->resolveVar($operands, $state),
            default => throw new WorkflowException("Unsupported JsonLogic operator {$operator}"),
        };
    }

    /** @param list<mixed> $rules */
    private function allTrue(array $rules, WorkflowState $state): bool
    {
        foreach ($rules as $rule) {
            if (!is_array($rule) || !$this->apply($rule, $state)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $rules */
    private function anyTrue(array $rules, WorkflowState $state): bool
    {
        foreach ($rules as $rule) {
            if (is_array($rule) && $this->apply($rule, $state)) {
                return true;
            }
        }

        return false;
    }

    private function resolve(mixed $operand, WorkflowState $state): mixed
    {
        if (is_array($operand) && isset($operand['var'])) {
            return $this->resolveVar($operand['var'], $state);
        }

        return $operand;
    }

    private function resolveVar(mixed $path, WorkflowState $state): mixed
    {
        if (is_string($path)) {
            $segments = explode('.', $path);
        } elseif (is_array($path)) {
            $segments = $path;
        } else {
            return null;
        }

        $value = ['data' => $state->data, 'nodeOutputs' => $state->nodeOutputs, 'feedback' => $state->get('feedback')];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
