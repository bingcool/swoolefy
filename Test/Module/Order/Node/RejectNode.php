<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 拒绝节点 —— AI 判定不批准时的终止分支。
 */
final class RejectNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);

        $decision = $state->get('decision');
        $reason = is_array($decision) ? (string) ($decision['reason'] ?? 'rejected') : 'rejected';

        $state->set('orderStatus', 'rejected');
        $state->set('rejectReason', $reason);
        $order = $state->get('order');
        if (is_array($order)) {
            $order['status'] = 'rejected';
            $order['rejectReason'] = $reason;
            $state->set('order', $order);
        }

        return NodeExecutionResult::success([
            'orderStatus' => 'rejected',
            'rejectReason' => $reason,
        ]);
    }
}
