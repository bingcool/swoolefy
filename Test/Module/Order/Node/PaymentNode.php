<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 支付节点 —— 直接批准或人工复核通过后执行扣款。
 *
 * Saga：compensate 模拟退款（paymentStatus: captured → refunded）。
 */
final class PaymentNode extends AbstractNode
{
    /** {@inheritdoc} 模拟支付成功，写入 paymentStatus=captured。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);
        $state->set('paymentStatus', 'captured');

        return NodeExecutionResult::success(['paymentStatus' => 'captured']);
    }

    /**
     * {@inheritdoc} Saga 补偿 —— 模拟退款。
     *
     * 幂等：仅当 status=captured 时改为 refunded。
     */
    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
        unset($ctx);
        if ($state->get('paymentStatus') === 'captured') {
            $state->set('paymentStatus', 'refunded');
        }
    }
}
