<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Test\Module\Order\Node\FailAfterPaymentNode;
use Test\Module\Order\Node\PaymentNode;
use Test\Module\Order\Node\ReserveInventoryNode;
use Test\Module\Order\Node\ValidateNode;

/**
 * 订单 Saga 补偿演示工作流（workflowId: order_saga，version: 1.1.0）。
 *
 * 演示「长事务」失败后的逆序补偿：正向链路在支付成功后故意让下游通知失败，
 * 引擎在 enableSaga() 开启时按已成功节点列表（executedNodeIds）逆序调用各节点的 compensate()。
 *
 * 正向 DAG（全部为固定边，无条件分支）：
 *
 *   validate → reserve → payment → notify_fail(FAILED)
 *
 * 失败后补偿顺序（逆序，仅对已成功执行的节点）：
 *
 *   notify_fail 未成功，不参与补偿
 *   payment.compensate()  → paymentStatus: captured → refunded（退款）
 *   reserve.compensate()  → inventoryReserved: true → false（释放库存）
 *   validate 无副作用，AbstractNode::compensate() 默认为空实现
 *
 * 各节点写入的 state 字段（细节见对应 Node）：
 *   - validate:     order{}、orderStatus=validated
 *   - reserve:      inventoryReserved=true、reservationId、orderStatus=inventory_reserved
 *   - payment:      payment{}、paymentStatus=captured、orderStatus=paid
 *   - notify_fail:  orderStatus=notify_failed，然后返回 FAILED（触发 Saga）
 *   - 补偿完成后:   compensatedNodes=["payment","reserve"]，
 *                   paymentStatus=refunded，inventoryReserved=false，
 *                   orderStatus 最终多为 inventory_released（最后一次 compensate 写入）
 *
 * 与 order_processing 的区别：
 *   - 本流程无 AI 决策、无条件边，专注 Saga 补偿语义
 *   - 必须调用 enableSaga()，否则节点失败只会标记 Run 失败，不会逆序 compensate
 *
 * 用法示例：
 *   $def = OrderSagaWorkflow::definition();
 *   $compiled = WorkflowBootstrap::compiler()->compile($def);
 *   $runId = WorkflowBootstrap::engine()->start($compiled, [
 *       'orderId' => 'ORD-SAGA-1',
 *       'amount'  => 50.0,
 *   ]);
 *
 * @see Test\Module\Order\README.md
 * @see Swoolefy\Support\Workflow\Engine\SagaCoordinator
 * @see docs/SwoolefyAI.md §4.2 onFail → compensate
 */
final class OrderSagaWorkflow
{
    /**
     * 构建纯工作流定义（仅描述 DAG，不启动引擎）。
     *
     * 运行时入口统一为：compile() 之后调用 WorkflowEngine::start()。
     * 当 notify_fail 返回 FAILED 时，引擎将 Run 置为 COMPENSATING，
     * 逆序补偿完成后状态为 COMPENSATED（或补偿异常时在 error 中追加 compensation 信息）。
     */
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::create('order_saga', '1.1.0')
            // 可选元数据，供注册中心 / 运维看板使用（引擎本身不依赖）。
            ->metadata([
                'owner' => 'order-team',
                'description' => 'Saga compensation demo after payment notify failure',
            ])
            // 开启 Saga：任一节点 FAILED 时，按 executedNodeIds 逆序调用 compensate()。
            // compensate 须幂等（可能重试或部分失败后再次调用）。
            ->enableSaga()

            // --- 节点（id 须与边的端点一致）----------------------------------
            // validate：校验 orderId，规范化入参，写入订单快照（通常无副作用，可不实现 compensate）。
            ->addNode('validate', new ValidateNode('validate'))
            // reserve：预占库存（正向）；compensate 释放预占（幂等）。
            ->addNode('reserve', new ReserveInventoryNode('reserve'))
            // payment：模拟扣款（正向）；compensate 将 captured 退款为 refunded（幂等）。
            ->addNode('payment', new PaymentNode('payment'))
            // notify_fail：故意失败，模拟「支付成功后下游通知不可用」，用于触发补偿链。
            // 本节点自身未成功，不会进入 compensatedNodes。
            ->addNode('notify_fail', new FailAfterPaymentNode('notify_fail'))

            // --- 固定边：线性推进，直到 notify_fail 失败 -------------------
            ->addEdge('validate', 'reserve')
            ->addEdge('reserve', 'payment')
            ->addEdge('payment', 'notify_fail');
    }
}
