<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Phase 1 工作流引擎回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | WorkflowCompiler | 环检测、条件边 default 必填、单入口约束 |
 * | 条件路由 | 高置信度 payment / 低置信度 manual_review / 拒绝 reject |
 * | Workflow Facade | fromDefinition()->compile()->start() 链式调用 |
 * | 插件 | TracingPlugin span 收集 |
 * | Neuron | ChatHistoryFactory 内存记忆 |
 * | Symfony EL | decision 分支表达式求值 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowPhase1Test.php
 * ```
 */

use Swoolefy\Support\Neuron\NeuronFactory;
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
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 通用断言
// ---------------------------------------------------------------------------

/** 断言条件为真，否则抛 RuntimeException 使单测失败。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------------------
// 编译期校验
// ---------------------------------------------------------------------------

/**
 * 验证：含 a↔b 双向边的图在编译时应抛出 WorkflowCompileException。
 *
 * 为何重要：DAG 不允许环，否则调度器可能死循环。
 */
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

/**
 * 验证：条件边未配置 default 分支时编译失败，且错误信息提及 default。
 *
 * 为何重要：运行时须保证每条出边有且仅有一条可走路径，缺 default 会导致未定义行为。
 */
function testCompilerRequiresConditionalDefault(): void
{
    $definition = \Swoolefy\Support\Workflow\Definition\WorkflowDefinition::create('no_default')
        ->addNode('a', new \Swoolefy\Support\Workflow\Node\ClosureNode('a', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addNode('b', new \Swoolefy\Support\Workflow\Node\ClosureNode('b', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addConditionalEdges('a', [
            'b' => EdgeCondition::when('true'),
        ]);

    try {
        (new WorkflowCompiler())->compile($definition);
        throw new RuntimeException('Expected missing default to fail at compile time');
    } catch (\Swoolefy\Support\Workflow\Exception\WorkflowCompileException $e) {
        assertTrue(str_contains($e->getMessage(), 'default'), 'message mentions default');
    }
}

/**
 * 验证：存在多个无入边入口节点时编译失败。
 *
 * 为何重要：引擎只执行单一入口，多入口图语义不明确，应在编译期拒绝。
 */
function testCompilerRejectsMultipleEntryNodes(): void
{
    $definition = \Swoolefy\Support\Workflow\Definition\WorkflowDefinition::create('multi_entry')
        ->addNode('entry_a', new \Swoolefy\Support\Workflow\Node\ClosureNode('entry_a', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addNode('entry_b', new \Swoolefy\Support\Workflow\Node\ClosureNode('entry_b', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addNode('join', new \Swoolefy\Support\Workflow\Node\ClosureNode('join', fn () => \Swoolefy\Support\Workflow\Engine\NodeExecutionResult::success()))
        ->addEdge('entry_a', 'join')
        ->addEdge('entry_b', 'join');

    try {
        (new WorkflowCompiler())->compile($definition);
        throw new RuntimeException('Expected multiple entry nodes to fail at compile time');
    } catch (\Swoolefy\Support\Workflow\Exception\WorkflowCompileException $e) {
        assertTrue(str_contains($e->getMessage(), 'exactly one entry'), 'message mentions single entry');
    }
}

// ---------------------------------------------------------------------------
// 订单处理条件路由（OrderProcessingFixtureWorkflow）
// ---------------------------------------------------------------------------

/**
 * 验证：高置信度批准（confidence≥0.8）应直连 payment，跳过 manual_review。
 *
 * 同时确认 TracingPlugin 记录了足够 span，便于可观测性回归。
 */
function testConditionalRoutingHighConfidence(): void
{
    $tracing = new TracingPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), $tracing]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
    );

    $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
        $dto = new DecisionDto();
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

    $decision = $run->state->dto(DecisionDto::class);
    assertTrue($decision->approved === true, 'Decision should be approved');
    assertTrue(count($tracing->spans()) >= 4, 'Tracing plugin should record spans');
}

/**
 * 验证：低置信度批准应经 manual_review 节点后再到 payment。
 *
 * 覆盖「批准但需人工复核」业务路径。
 */
function testConditionalRoutingManualReview(): void
{
    $engine = WorkflowBootstrap::engine();

    $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
        $dto = new DecisionDto();
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

/**
 * 验证：拒绝决策应路由到 reject，且不执行 payment 捕获。
 *
 * 确保拒绝路径与支付路径互斥。
 */
function testConditionalRoutingReject(): void
{
    $engine = WorkflowBootstrap::engine();

    $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
        $dto = new DecisionDto();
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

// ---------------------------------------------------------------------------
// Facade 与基础设施
// ---------------------------------------------------------------------------

/**
 * 验证：Workflow Facade 链式 compile/start 可完成整次 Run。
 *
 * 非协程 CLI 无 Context 缓存，须显式传入同一 Engine 实例。
 */
function testWorkflowFacade(): void
{
    WorkflowBootstrap::reset();

    $engine = WorkflowBootstrap::engine();

    $runId = Workflow::fromDefinition(OrderProcessingFixtureWorkflow::definition(new NeuronFactory()))
        ->compile()
        ->start([
            'orderId' => 10004,
            'sessionId' => 's-jkl',
        ], $engine);

    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'completed', 'Facade run should complete');
}

/**
 * 验证：ChatHistoryFactory::inMemory 返回可用的进程内会话记忆实现。
 *
 * 供 Neuron Agent 单测与 CLI 场景使用，无需外部存储。
 */
function testChatHistoryFactoryInMemory(): void
{
    $history = \Swoolefy\Support\Neuron\Memory\ChatHistoryFactory::inMemory();
    assertTrue($history instanceof \NeuronAI\Chat\History\ChatHistoryInterface, 'ChatHistoryFactory should return chat history');
}

/**
 * 验证：Symfony ExpressionLanguage 能正确求值 decision 分支条件。
 *
 * 条件边依赖 data['decision'] 结构，本用例隔离验证求值器本身。
 */
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

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'compiler cycle detection' => 'testCompilerDetectsCycle',
    'compiler requires conditional default' => 'testCompilerRequiresConditionalDefault',
    'compiler rejects multiple entry nodes' => 'testCompilerRejectsMultipleEntryNodes',
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
