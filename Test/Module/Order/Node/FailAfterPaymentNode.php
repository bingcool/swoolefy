<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use RuntimeException;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 故意失败节点 —— Saga 演示触发补偿链（下游通知不可用）。
 */
final class FailAfterPaymentNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);
        $state->set('orderStatus', 'notify_failed');
        $order = $state->get('order');
        if (is_array($order)) {
            $order['status'] = 'notify_failed';
            $state->set('order', $order);
        }

        return NodeExecutionResult::failed(new RuntimeException('Downstream notify service unavailable'));
    }
}
