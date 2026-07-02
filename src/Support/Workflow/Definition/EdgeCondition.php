<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

/**
 * 条件边描述符 —— 求值委托给 {@see ConditionEvaluatorInterface}。
 *
 * Symfony EL 示例（求值器注入的变量）：
 *   data['decision']['approved'] == true
 *   data['decision']['confidence'] >= 0.8
 *   feedback.approved == true（HITL resume 后）
 *
 * @see swoolefyAI.md §4.9.2
 */
final class EdgeCondition
{
    public const TYPE_EXPRESSION = 'expression';
    public const TYPE_CALLABLE = 'callable';
    public const TYPE_ALWAYS = 'always';
    public const TYPE_JSON_LOGIC = 'jsonlogic';

    private function __construct(
        public readonly string $type,
        public readonly ?string $expression = null,
        public mixed $callable = null,
        public readonly ?array $jsonLogic = null,
    ) {
    }

    /**
     * Symfony 表达式字符串（Phase 1 默认求值器）。
     */
    public static function when(string $expression): self
    {
        return new self(self::TYPE_EXPRESSION, expression: $expression);
    }

    /** 恒为 true 的条件（少见，更推荐 addConditionalEdges 的 default 参数）。 */
    public static function always(): self
    {
        return new self(self::TYPE_ALWAYS);
    }

    /**
     * PHP 可调用条件，复杂逻辑推荐在闭包内使用 WorkflowState::dto()。
     *
     * @param callable(\Swoolefy\Support\Workflow\State\WorkflowState): bool $fn
     */
    public static function fromCallable(callable $fn): self
    {
        return new self(self::TYPE_CALLABLE, callable: $fn);
    }

    /**
     * JSON Logic 规则（Phase 2+ 配置化路由）。
     *
     * @param array<string, mixed> $rule
     */
    public static function fromJsonLogic(array $rule): self
    {
        return new self(self::TYPE_JSON_LOGIC, jsonLogic: $rule);
    }
}
