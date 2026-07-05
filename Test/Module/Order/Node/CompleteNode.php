<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 订单完成节点 —— 支付成功后的终态。
 */
final class CompleteNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);

        $state->set('orderStatus', 'completed');
        $order = $state->get('order');
        if (is_array($order)) {
            $order['status'] = 'completed';
            $order['completedAt'] = date('c');
            $state->set('order', $order);
        }

        return NodeExecutionResult::success([
            'orderStatus' => 'completed',
            'order' => $state->get('order'),
        ]);
    }
}
