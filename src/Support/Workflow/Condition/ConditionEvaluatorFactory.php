<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Condition;

/**
 * 条件求值器工厂。
 *
 * 解析优先级：
 *   1. 显式传入的 $driver（如 WorkflowConfig::conditionEvaluator()）
 *   2. 环境变量 WORKFLOW_CONDITION_EVALUATOR
 *   3. 默认 symfony
 *
 * symfony / jsonlogic / composite：CompositeConditionEvaluator（按 EdgeCondition.type 路由）
 * 其它未知值：回退 SymfonyExpressionLanguageEvaluator
 */
final class ConditionEvaluatorFactory
{
    public static function create(?string $driver = null): ConditionEvaluatorInterface
    {
        $resolved = is_string($driver) ? trim($driver) : '';
        if ($resolved === '') {
            $resolved = (string) (env('WORKFLOW_CONDITION_EVALUATOR', 'symfony') ?: 'symfony');
        }
        $resolved = strtolower($resolved);

        return match ($resolved) {
            'symfony', 'jsonlogic', 'composite' => new CompositeConditionEvaluator(),
            default => new SymfonyExpressionLanguageEvaluator(),
        };
    }
}
