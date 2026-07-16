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
 * Phase 2 工作流回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | Agent 路由 | StaticRouter 固定列表、RuleRouter 表达式选路 |
 * | 流式事件 | StreamBridge 在协程内收集 token/edge 事件 |
 * | MetricsPlugin | 节点与 run 计数快照 |
 * | WorkflowRegistry | 注册、has、compiled 工作流 ID |
 * | 多 Agent | MultiAgentResearchFixtureWorkflow 端到端完成与输出 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowPhase2Test.php
 * ```
 */

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\RuleRouter;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\AI\Stream\CollectingStreamSink;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Plugin\Builtin\MetricsPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;
use Swoolefy\Support\Workflow\Tests\Fixtures\MultiAgentResearchFixtureWorkflow;

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
// Agent 路由
// ---------------------------------------------------------------------------

/**
 * 验证：StaticRouter 无视上下文，始终返回构造时固定的 agent ID 列表。
 *
 * 用于多 Agent 并行或固定编排场景。
 */
function testStaticRouter(): void
{
    $router = new StaticRouter(['coding', 'finance']);
    $ctx = new RouterContext('run_1', WorkflowState::fromInput(['query' => 'test']));
    $ids = $router->route($ctx);
    assertTrue($ids === ['coding', 'finance'], 'StaticRouter should return fixed ids');
}

/**
 * 验证：RuleRouter 根据 Symfony EL 条件从 data 字段选择匹配的 agent。
 *
 * topic=tech 时应仅路由到 coding。
 */
function testRuleRouter(): void
{
    $evaluator = new SymfonyExpressionLanguageEvaluator();
    $router = new RuleRouter([
        'coding' => EdgeCondition::when("data['topic'] == 'tech'"),
        'finance' => EdgeCondition::when("data['topic'] == 'money'"),
    ], $evaluator);

    $ctx = new RouterContext('run_2', WorkflowState::fromInput(['topic' => 'tech']));
    assertTrue($router->route($ctx) === ['coding'], 'RuleRouter should select coding for tech topic');
}

// ---------------------------------------------------------------------------
// 流式事件桥接
// ---------------------------------------------------------------------------

/**
 * 验证：StreamBridge 绑定的 CollectingStreamSink 能收到 emit 的 token 与 edge.route 事件。
 *
 * 非协程 CLI 须通过 Swoole\Coroutine\run 包裹，模拟 Worker 协程环境。
 */
function testStreamBridgeCollectsEvents(): void
{
    $run = static function (): void {
        $sink = new CollectingStreamSink();
        StreamBridge::bind($sink);

        StreamBridge::emit('token', ['content' => 'hello']);
        StreamBridge::emit('edge.route', ['from' => 'a', 'to' => 'b']);

        assertTrue(count($sink->events()) === 2, 'StreamBridge should forward two events');
        StreamBridge::unbind();
    };

    if (\Swoole\Coroutine::getCid() >= 0) {
        $run();

        return;
    }

    \Swoole\Runtime::enableCoroutine();
    \Swoole\Coroutine\run($run);
}

// ---------------------------------------------------------------------------
// 插件与注册表
// ---------------------------------------------------------------------------

/**
 * 验证：MetricsPlugin 在 OrderProcessingFixtureWorkflow 执行后 snapshot 中 runs/nodes 计数 ≥1。
 *
 * 确保指标插件正确挂钩引擎生命周期。
 */
function testMetricsPluginCountsNodes(): void
{
    $metrics = new MetricsPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), $metrics]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        events: new StreamWorkflowEventDispatcher(),
    );

    $compiled = WorkflowBootstrap::compiler()->compile(OrderProcessingFixtureWorkflow::definition(new NeuronFactory()));
    $engine->start($compiled, ['orderId' => 20001, 'sessionId' => 's-p2']);

    $snapshot = $metrics->snapshot();
    assertTrue(($snapshot['runs'] ?? 0) >= 1, 'MetricsPlugin should count runs');
    assertTrue(($snapshot['nodes'] ?? 0) >= 1, 'MetricsPlugin should count nodes');
}

/**
 * 验证：WorkflowRegistry register/has/compiled 与 definition 工厂联动正常。
 *
 * compiled 返回的 workflowId 应与注册名一致。
 */
function testWorkflowRegistry(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('order_processing', static fn () => OrderProcessingFixtureWorkflow::definition(new NeuronFactory()));
    assertTrue($registry->has('order_processing'), 'Registry should know order_processing');
    $compiled = $registry->compiled('order_processing');
    assertTrue($compiled->workflowId() === 'order_processing', 'Compiled workflow id should match');
}

// ---------------------------------------------------------------------------
// 多 Agent 研究工作流
// ---------------------------------------------------------------------------

/**
 * 验证：MultiAgentResearchFixtureWorkflow 端到端完成，产出双 agent 输出与 summary。
 *
 * 覆盖 AgentScheduler + 流式事件分发 + 多节点 DAG 集成。
 */
function testMultiAgentResearchFixtureWorkflow(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $definition = MultiAgentResearchFixtureWorkflow::definition($scheduler);
    $compiled = WorkflowBootstrap::compiler()->compile($definition);

    $engine = new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), new TracingPlugin()]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        events: new StreamWorkflowEventDispatcher(),
    );

    $runId = $engine->start($compiled, ['query' => 'Analyze swoolefy workflow design']);
    $run = $engine->getRun($runId);

    assertTrue($run->status->value === 'completed', 'Research workflow should complete');
    assertTrue(count($run->state->agentOutputs) === 2, 'Should have coding and finance outputs');
    assertTrue(isset($run->state->data['summary']), 'Summary node should write data.summary');
}

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'static router' => 'testStaticRouter',
    'rule router' => 'testRuleRouter',
    'stream bridge' => 'testStreamBridgeCollectsEvents',
    'metrics plugin' => 'testMetricsPluginCountsNodes',
    'workflow registry' => 'testWorkflowRegistry',
    'multi agent research' => 'testMultiAgentResearchFixtureWorkflow',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Phase 2 workflow tests passed.\n";
