<?php

declare(strict_types=1);

/**
 * Order 模块工作流独立性回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | OrderWorkflowService | 独立 Registry，不依赖 Workflow 模块单例 |
 * | order_processing mock | 高置信度批准 → payment → completed |
 * | order_processing mock | 拒绝 → reject，无支付 |
 * | order_saga | 通知失败触发 COMPENSATED |
 *
 * ## 运行
 * ```bash
 * php Test/Module/Order/Tests/OrderWorkflowModuleTest.php
 * ```
 */

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
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeEngine(?InMemoryRunStore $store = null): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), new TracingPlugin()]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        runStore: $store ?? new InMemoryRunStore(),
    );
}

function makeCompiler(): WorkflowCompiler
{
    return new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
}

/**
 * 验证：Order 注册表与 Workflow 模块注册表是不同实例，且仅含 order_*。
 */
function testOrderRegistryIsIndependent(): void
{
    OrderWorkflowService::reset();
    WorkflowService::reset();

    $order = OrderWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($order !== $central, 'Order registry must be a distinct instance');
    assertTrue($order->has('order_processing'), 'Order registry should register order_processing');
    assertTrue($order->has('order_saga'), 'Order registry should register order_saga');
    $ids = $order->ids();
    sort($ids);
    assertTrue($ids === ['order_processing', 'order_saga'], 'Order registry should only own order workflows');
}

/**
 * 验证：mock 高置信度批准走支付并完成（经本模块 Engine）。
 */
function testOrderProcessingApprovedMock(): void
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
    $runId = $engine->start(makeCompiler()->compile($definition), [
        'orderId' => 'ORD-MOD-1',
        'userId' => 'u1',
        'sessionId' => 's-mod-1',
        'amount' => 120.0,
    ]);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'Order processing should complete');
    assertTrue($run->state->get('orderStatus') === 'completed', 'orderStatus should be completed');
    assertTrue($run->state->get('paymentStatus') === 'captured', 'Should capture payment');
}

/**
 * 验证：mock 拒绝路径不支付。
 */
function testOrderProcessingRejectedMock(): void
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

    $engine = makeEngine();
    $runId = $engine->start(makeCompiler()->compile($definition), [
        'orderId' => 'ORD-MOD-2',
        'sessionId' => 's-mod-2',
    ]);
    $run = $engine->getRun($runId);

    assertTrue($run->state->get('orderStatus') === 'rejected', 'Should route to reject');
    assertTrue($run->state->get('paymentStatus') === null, 'Should not capture payment');
}

/**
 * 验证：Saga 在支付后失败时进入 COMPENSATED。
 */
function testOrderSagaCompensation(): void
{
    OrderWorkflowService::reset();

    $store = new InMemoryRunStore();
    $engine = makeEngine($store);
    $compiled = makeCompiler()->compile(OrderSagaWorkflow::definition());

    try {
        $engine->start($compiled, ['orderId' => 'ORD-MOD-SAGA-1']);
        assertTrue(false, 'saga workflow should throw on notify_fail');
    } catch (WorkflowException) {
        // expected
    }

    $runs = $store->all();
    assertTrue(count($runs) >= 1, 'should have saved run');
    $run = $runs[count($runs) - 1];
    assertTrue($run->status === RunStatus::COMPENSATED, 'run should be COMPENSATED after saga');
    assertTrue($run->state->get('paymentStatus') === 'refunded', 'payment should be refunded');
    assertTrue($run->state->get('inventoryReserved') === false, 'inventory should be released');
}

$tests = [
    'order registry independent' => 'testOrderRegistryIsIndependent',
    'order processing approved mock' => 'testOrderProcessingApprovedMock',
    'order processing rejected mock' => 'testOrderProcessingRejectedMock',
    'order saga compensation' => 'testOrderSagaCompensation',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Order workflow module tests passed.\n";
