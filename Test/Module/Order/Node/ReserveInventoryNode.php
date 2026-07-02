<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 库存预占节点 —— Saga 演示正向动作。
 *
 * execute：设置 inventoryReserved=true
 * compensate：释放预占 inventoryReserved=false（须幂等）
 */
final class ReserveInventoryNode extends AbstractNode
{
    /** {@inheritdoc} 模拟预占库存。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);
        $state->set('inventoryReserved', true);

        return NodeExecutionResult::success(['inventoryReserved' => true]);
    }

    /** {@inheritdoc} Saga 回滚 —— 释放库存预占。 */
    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
        unset($ctx);
        if ($state->get('inventoryReserved') === true) {
            $state->set('inventoryReserved', false);
        }
    }
}
