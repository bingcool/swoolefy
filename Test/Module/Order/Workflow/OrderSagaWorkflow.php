<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Test\Module\Order\Node\FailAfterPaymentNode;
use Test\Module\Order\Node\PaymentNode;
use Test\Module\Order\Node\ReserveInventoryNode;
use Test\Module\Order\Node\ValidateNode;

/**
 * Saga 补偿示例（workflowId: order_saga）。
 *
 *   validate → reserve → payment → notify_fail(FAILED)
 *   compensate 逆序：payment 退款 → reserve 释库存
 */
final class OrderSagaWorkflow
{
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::create('order_saga', '1.1.0')
            ->metadata([
                'owner' => 'order-team',
                'description' => 'Saga compensation demo after payment notify failure',
            ])
            ->enableSaga()
            ->addNode('validate', new ValidateNode('validate'))
            ->addNode('reserve', new ReserveInventoryNode('reserve'))
            ->addNode('payment', new PaymentNode('payment'))
            ->addNode('notify_fail', new FailAfterPaymentNode('notify_fail'))
            ->addEdge('validate', 'reserve')
            ->addEdge('reserve', 'payment')
            ->addEdge('payment', 'notify_fail');
    }
}
