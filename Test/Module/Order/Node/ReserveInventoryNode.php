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
 * execute：inventoryReserved=true
 * compensate：释放预占（幂等）
 */
final class ReserveInventoryNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);

        $orderId = $state->get('orderId');
        $reservationId = 'rsv_' . $orderId . '_' . substr(md5((string) microtime(true)), 0, 8);

        $state->set('inventoryReserved', true);
        $state->set('reservationId', $reservationId);
        $state->set('orderStatus', 'inventory_reserved');
        $order = $state->get('order');
        if (is_array($order)) {
            $order['status'] = 'inventory_reserved';
            $order['reservationId'] = $reservationId;
            $state->set('order', $order);
        }

        return NodeExecutionResult::success([
            'inventoryReserved' => true,
            'reservationId' => $reservationId,
        ]);
    }

    /** {@inheritdoc} */
    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
        unset($ctx);
        if ($state->get('inventoryReserved') !== true) {
            return;
        }

        $state->set('inventoryReserved', false);
        $order = $state->get('order');
        if (is_array($order)) {
            $order['status'] = 'inventory_released';
            $state->set('order', $order);
        }
        $state->set('orderStatus', 'inventory_released');
    }
}
