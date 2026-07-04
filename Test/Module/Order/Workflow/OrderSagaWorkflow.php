<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Test\Module\Order\Node\FailAfterPaymentNode;
use Test\Module\Order\Node\PaymentNode;
use Test\Module\Order\Node\ReserveInventoryNode;
use Test\Module\Order\Node\ValidateNode;

/**
 * Phase 4 Saga 补偿示例工作流（workflowId: order_saga）。
 *
 * DAG：
 *   validate → reserve → payment → notify_fail
 *
 * 失败路径（notify_fail 固定 FAILED）：
 *   1. executedNodeIds = [validate, reserve, payment]
 *   2. SagaCoordinator 逆序：payment.compensate（退款）→ reserve.compensate（释库存）
 *   3. Run.status = COMPENSATED，state.compensatedNodes 记录已补偿节点
 *
 * 启用方式：WorkflowDefinition::enableSaga() → metadata.saga = true
 *
 * @see docs/swoolefyAI.md Phase 4 SagaCoordinator
 */
final class OrderSagaWorkflow
{
    /** 构建带 Saga 的订单补偿演示定义。 */
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::create('order_saga', '1.0.0')
            ->metadata(['owner' => 'order-team', 'description' => 'Saga compensation demo'])
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
