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
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 可插拔条件求值器接口。
 *
 * Phase 1 默认：{@see SymfonyExpressionLanguageEvaluator}
 * Phase 2+：JsonLogicEvaluator、CelEvaluator 等
 *
 * @see docs/SwoolefyAI.md §4.9.2
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
