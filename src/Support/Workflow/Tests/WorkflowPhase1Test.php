<?php

declare(strict_types=1);

/**
 * Phase 1 工作流引擎回归测试。
 *
 * 覆盖：
 *   - 编译器：环检测、入口节点
 *   - 条件边：Symfony EL 读 data['decision']
 *   - 引擎：三条路由（payment / manual_review / reject）
 *   - Facade：Workflow::fromDefinition()->compile()->start()
 *   - 插件：TracingPlugin span 收集
 *   - Neuron：ChatHistoryFactory 内存记忆
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowPhase1Test.php
 */

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\Workflow;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** 断言条件为真，否则抛 RuntimeException。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 测试：编译器应检测到 a↔b 环并抛 WorkflowCompileException。 */
function testCompilerDetectsCycle(): void
{
    $definition = \Swoolefy\Support\Workflow\Definition\WorkflowDefinition::create('cycle')
        ->addNode('a', new \Swoolefy\Support\Workflow\Node\ClosureNode('a', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addNode('b', new \Swoolefy\Support\Workflow\Node\ClosureNode('b', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addEdge('a', 'b')
        ->addEdge('b', 'a');

    $compiler = WorkflowBootstrap::compiler();

    try {
        $compiler->compile($definition);
        throw new RuntimeException('Expected cycle detection to fail');
    } catch (\Swoolefy\Support\Workflow\Exception\WorkflowCompileException) {
    }
}

/** 测试：高置信度批准应直连 payment，跳过 manual_review。 */
function testConditionalRoutingHighConfidence(): void
{
    $tracing = new TracingPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), $tracing]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
    );

    $definition = OrderProcessingWorkflow::definition(static function ($ctx, $state): OrderDecisionDto {
        $dto = new OrderDecisionDto();
        $dto->approved = true;
        $dto->confidence = 0.95;
        $dto->reason = 'High confidence approve';

        return $dto;
    });

    $compiler = new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
    $compiled = $compiler->compile($definition);

    $runId = $engine->start($compiled, [
        'orderId' => 10001,
        'userId' => 'u123',
        'sessionId' => 's-abc',
    ]);

    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'completed', 'Run should complete');
    assertTrue($run->state->get('paymentStatus') === 'captured', 'Should route to payment directly');
    assertTrue($run->state->get('manualReview') !== true, 'Should skip manual review');

    $decision = $run->state->dto(OrderDecisionDto::class);
    assertTrue($decision->approved === true, 'Decision should be approved');
    assertTrue(count($tracing->spans()) >= 4, 'Tracing plugin should record spans');
}

/** 测试：低置信度批准应经 manual_review 再到 payment。 */
function testConditionalRoutingManualReview(): void
{
    $engine = WorkflowBootstrap::engine();

    $definition = OrderProcessingWorkflow::definition(static function ($ctx, $state): OrderDecisionDto {
        $dto = new OrderDecisionDto();
        $dto->approved = true;
        $dto->confidence = 0.55;
        $dto->reason = 'Low confidence approve';

        return $dto;
    });

    $compiled = WorkflowBootstrap::compiler()->compile($definition);
    $runId = $engine->start($compiled, ['orderId' => 10002, 'sessionId' => 's-def']);
    $run = $engine->getRun($runId);

    assertTrue($run->state->get('manualReview') === true, 'Should pass manual review');
    assertTrue($run->state->get('paymentStatus') === 'captured', 'Should continue to payment');
}

/** 测试：拒绝应路由到 reject，不执行 payment。 */
function testConditionalRoutingReject(): void
{
    $engine = WorkflowBootstrap::engine();

    $definition = OrderProcessingWorkflow::definition(static function ($ctx, $state): OrderDecisionDto {
        $dto = new OrderDecisionDto();
        $dto->approved = false;
        $dto->confidence = 0.99;
        $dto->reason = 'Rejected by policy';

        return $dto;
    });

    $compiled = WorkflowBootstrap::compiler()->compile($definition);
    $runId = $engine->start($compiled, ['orderId' => 10003, 'sessionId' => 's-ghi']);
    $run = $engine->getRun($runId);

    assertTrue($run->state->get('orderStatus') === 'rejected', 'Should route to reject');
    assertTrue($run->state->get('paymentStatus') === null, 'Should not capture payment');
}

/** 测试：Workflow Facade 链式调用可完成整次 Run。 */
function testWorkflowFacade(): void
{
    WorkflowBootstrap::reset();

    // 非协程 CLI 无 Context 缓存，须显式复用同一 Engine 实例
    $engine = WorkflowBootstrap::engine();

    $runId = Workflow::fromDefinition(OrderProcessingWorkflow::definition())
        ->compile()
        ->start([
            'orderId' => 10004,
            'sessionId' => 's-jkl',
        ], $engine);

    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'completed', 'Facade run should complete');
}

/** 测试：ChatHistoryFactory::inMemory 返回进程内会话记忆。 */
function testChatHistoryFactoryInMemory(): void
{
    $history = \Swoolefy\Support\Neuron\Memory\ChatHistoryFactory::inMemory();
    assertTrue($history instanceof \NeuronAI\Chat\History\ChatHistoryInterface, 'ChatHistoryFactory should return chat history');
}

/** 测试：Symfony EL 能正确求值 decision 分支条件。 */
function testExpressionEvaluator(): void
{
    $evaluator = new SymfonyExpressionLanguageEvaluator();
    $state = WorkflowState::fromInput([
        'decision' => ['approved' => true, 'confidence' => 0.9],
    ]);

    assertTrue(
        $evaluator->evaluate(
            EdgeCondition::when("data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8"),
            $state,
        ),
        'Symfony EL should evaluate decision branch',
    );
}

$tests = [
    'compiler cycle detection' => 'testCompilerDetectsCycle',
    'expression evaluator' => 'testExpressionEvaluator',
    'chat history factory in-memory' => 'testChatHistoryFactoryInMemory',
    'route high confidence' => 'testConditionalRoutingHighConfidence',
    'route manual review' => 'testConditionalRoutingManualReview',
    'route reject' => 'testConditionalRoutingReject',
    'workflow facade' => 'testWorkflowFacade',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Phase 1 workflow tests passed.\n";
