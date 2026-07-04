<?php

declare(strict_types=1);

/**
 * Phase 2 工作流回归测试。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowPhase2Test.php
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
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testStaticRouter(): void
{
    $router = new StaticRouter(['coding', 'finance']);
    $ctx = new RouterContext('run_1', WorkflowState::fromInput(['query' => 'test']));
    $ids = $router->route($ctx);
    assertTrue($ids === ['coding', 'finance'], 'StaticRouter should return fixed ids');
}

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

function testMetricsPluginCountsNodes(): void
{
    $metrics = new MetricsPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), $metrics]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        events: new StreamWorkflowEventDispatcher(),
    );

    $compiled = WorkflowBootstrap::compiler()->compile(OrderProcessingWorkflow::definition());
    $engine->start($compiled, ['orderId' => 20001, 'sessionId' => 's-p2']);

    $snapshot = $metrics->snapshot();
    assertTrue(($snapshot['runs'] ?? 0) >= 1, 'MetricsPlugin should count runs');
    assertTrue(($snapshot['nodes'] ?? 0) >= 1, 'MetricsPlugin should count nodes');
}

function testMultiAgentResearchWorkflow(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $definition = MultiAgentResearchWorkflow::definition($scheduler);
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

function testWorkflowRegistry(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('order_processing', static fn () => OrderProcessingWorkflow::definition());
    assertTrue($registry->has('order_processing'), 'Registry should know order_processing');
    $compiled = $registry->compiled('order_processing');
    assertTrue($compiled->workflowId() === 'order_processing', 'Compiled workflow id should match');
}

$tests = [
    'static router' => 'testStaticRouter',
    'rule router' => 'testRuleRouter',
    'stream bridge' => 'testStreamBridgeCollectsEvents',
    'metrics plugin' => 'testMetricsPluginCountsNodes',
    'workflow registry' => 'testWorkflowRegistry',
    'multi agent research' => 'testMultiAgentResearchWorkflow',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Phase 2 workflow tests passed.\n";
