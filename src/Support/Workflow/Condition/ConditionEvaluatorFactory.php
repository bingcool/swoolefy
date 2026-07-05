<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Condition;

/**
 * 条件求值器工厂 —— 按 WORKFLOW_CONDITION_EVALUATOR 环境变量创建实例。
 *
 * symfony（默认）：Symfony EL + callable + always（经 Composite）
 * jsonlogic：同上，并启用 JsonLogic 规则
 */
final class ConditionEvaluatorFactory
{
    public static function create(?string $driver = null): ConditionEvaluatorInterface
    {
        $driver = strtolower(env('WORKFLOW_CONDITION_EVALUATOR','symfony') ?: 'symfony');

        return match ($driver) {
            'symfony', 'jsonlogic', 'composite' => new CompositeConditionEvaluator(),
            default => new SymfonyExpressionLanguageEvaluator(),
        };
    }
}
