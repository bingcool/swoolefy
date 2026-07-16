<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use RuntimeException;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Support 单测用 order_saga（含可补偿节点，不依赖 Test\Module\Order）。
 */
final class OrderSagaFixtureWorkflow
{
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::create('order_saga', '1.1.0')
            ->metadata([
                'owner' => 'support-tests',
                'description' => 'Fixture saga compensation after notify failure',
            ])
            ->enableSaga()
            ->addNode('validate', new class('validate') extends AbstractNode {
                public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
                {
                    unset($ctx);
                    $orderId = $state->get('orderId');
                    if ($orderId === null || $orderId === '') {
                        return NodeExecutionResult::failed(new \InvalidArgumentException('orderId required'));
                    }

                    return NodeExecutionResult::success([
                        'orderId' => $orderId,
                        'order' => ['orderId' => $orderId, 'status' => 'validated'],
                        'orderStatus' => 'validated',
                        'amount' => (float) ($state->get('amount') ?? 0),
                    ]);
                }
            })
            ->addNode('reserve', new class('reserve') extends AbstractNode {
                public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
                {
                    unset($ctx);
                    $state->set('inventoryReserved', true);
                    $state->set('orderStatus', 'inventory_reserved');

                    return NodeExecutionResult::success(['inventoryReserved' => true]);
                }

                public function compensate(RunContext $ctx, WorkflowState $state): void
                {
                    unset($ctx);
                    if ($state->get('inventoryReserved') !== true) {
                        return;
                    }
                    $state->set('inventoryReserved', false);
                    $state->set('orderStatus', 'inventory_released');
                }
            })
            ->addNode('payment', new class('payment') extends AbstractNode {
                public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
                {
                    unset($ctx);
                    $state->set('paymentStatus', 'captured');
                    $state->set('orderStatus', 'paid');

                    return NodeExecutionResult::success(['paymentStatus' => 'captured']);
                }

                public function compensate(RunContext $ctx, WorkflowState $state): void
                {
                    unset($ctx);
                    if ($state->get('paymentStatus') !== 'captured') {
                        return;
                    }
                    $state->set('paymentStatus', 'refunded');
                    $state->set('orderStatus', 'refunded');
                }
            })
            ->addNode('notify_fail', new class('notify_fail') extends AbstractNode {
                public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
                {
                    unset($ctx);
                    $state->set('orderStatus', 'notify_failed');

                    return NodeExecutionResult::failed(new RuntimeException('Downstream notify service unavailable'));
                }
            })
            ->addEdge('validate', 'reserve')
            ->addEdge('reserve', 'payment')
            ->addEdge('payment', 'notify_fail');
    }
}
