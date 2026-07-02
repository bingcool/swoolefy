<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 订单校验节点 —— 校验必填输入后再进入 AI 决策。
 */
final class ValidateNode extends AbstractNode
{
    /** {@inheritdoc} 校验 orderId 存在。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $orderId = $state->get('orderId');
        if ($orderId === null) {
            return NodeExecutionResult::failed(new \InvalidArgumentException('orderId is required'));
        }

        $state->set('validated', true);

        return NodeExecutionResult::success(['validated' => true, 'orderId' => $orderId]);
    }
}
