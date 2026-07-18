<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Order;

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use PhpUintTest\TestCase;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * Order 模块工作流独立性回归测试。
 */
final class OrderWorkflowModuleTest extends TestCase
{
    public function testOrderRegistryIsIndependent(): void
    {
        OrderWorkflowService::reset();
        WorkflowService::reset();

        $order = OrderWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $order, 'Order registry must be a distinct instance');
        $this->assertTrue($order->has('order_processing'), 'Order registry should register order_processing');
        $this->assertTrue($order->has('order_saga'), 'Order registry should register order_saga');
        $ids = $order->ids();
        sort($ids);
        $this->assertSame(['order_processing', 'order_saga'], $ids, 'Order registry should only own order workflows');
    }

    public function testOrderProcessingApprovedMock(): void
    {
        OrderWorkflowService::reset();

        $definition = OrderProcessingWorkflow::definition(
            OrderWorkflowService::neuronFactory(),
            static function ($ctx, $state): OrderDecisionDto {
                unset($ctx, $state);
                $dto = new OrderDecisionDto();
                $dto->approved = true;
                $dto->confidence = 0.95;
                $dto->reason = 'module test approve';

                return $dto;
            },
        );

        $engine = OrderWorkflowService::engine();
        $runId = $engine->start($this->makeCompiler()->compile($definition), [
            'orderId' => 'ORD-MOD-1',
            'userId' => 'u1',
            'sessionId' => 's-mod-1',
            'amount' => 120.0,
        ]);
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status, 'Order processing should complete');
        $this->assertSame('completed', $run->state->get('orderStatus'), 'orderStatus should be completed');
        $this->assertSame('captured', $run->state->get('paymentStatus'), 'Should capture payment');
    }

    public function testOrderProcessingRejectedMock(): void
    {
        OrderWorkflowService::reset();

        $definition = OrderProcessingWorkflow::definition(
            OrderWorkflowService::neuronFactory(),
            static function ($ctx, $state): OrderDecisionDto {
                unset($ctx, $state);
                $dto = new OrderDecisionDto();
                $dto->approved = false;
                $dto->confidence = 0.99;
                $dto->reason = 'module test reject';

                return $dto;
            },
        );

        $engine = $this->makeEngine();
        $runId = $engine->start($this->makeCompiler()->compile($definition), [
            'orderId' => 'ORD-MOD-2',
            'sessionId' => 's-mod-2',
        ]);
        $run = $engine->getRun($runId);

        $this->assertSame('rejected', $run->state->get('orderStatus'), 'Should route to reject');
        $this->assertNull($run->state->get('paymentStatus'), 'Should not capture payment');
    }

    public function testOrderSagaCompensation(): void
    {
        OrderWorkflowService::reset();

        $store = new InMemoryRunStore();
        $engine = $this->makeEngine($store);
        $compiled = $this->makeCompiler()->compile(OrderSagaWorkflow::definition());

        try {
            $engine->start($compiled, ['orderId' => 'ORD-MOD-SAGA-1']);
            $this->fail('saga workflow should throw on notify_fail');
        } catch (WorkflowException) {
            // expected
        }

        $runs = $store->all();
        $this->assertGreaterThanOrEqual(1, count($runs), 'should have saved run');
        $run = $runs[count($runs) - 1];
        $this->assertSame(RunStatus::COMPENSATED, $run->status, 'run should be COMPENSATED after saga');
        $this->assertSame('refunded', $run->state->get('paymentStatus'), 'payment should be refunded');
        $this->assertFalse($run->state->get('inventoryReserved'), 'inventory should be released');
    }

    private function makeEngine(?InMemoryRunStore $store = null): WorkflowEngine
    {
        return new WorkflowEngine(
            plugins: new PluginManager([new RetryPlugin(), new TracingPlugin()]),
            scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
            runStore: $store ?? new InMemoryRunStore(),
        );
    }

    private function makeCompiler(): WorkflowCompiler
    {
        return new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
    }
}
