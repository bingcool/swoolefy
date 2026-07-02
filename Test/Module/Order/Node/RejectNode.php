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
    /** {@inheritdoc} 标记订单被拒绝。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $state->set('orderStatus', 'rejected');

        return NodeExecutionResult::success(['orderStatus' => 'rejected']);
    }
}
