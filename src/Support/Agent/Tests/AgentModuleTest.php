<?php

declare(strict_types=1);

/**
 * Agent 模块回归测试。
 *
 * 覆盖：Static / Rule / Weighted / CostAware / RoundRobin 路由、AgentScheduler 串行执行与 agentOutputs。
 *
 * 运行：php src/Support/Agent/Tests/AgentModuleTest.php
 * 或：composer test:agent
 */

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\CostAwareRouter;
use Swoolefy\Support\Agent\Router\RoundRobinRouter;
use Swoolefy\Support\Agent\Router\RuleRouter;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\Router\WeightedRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Support\AI\Node\AgentParallelNode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\State\WorkflowState;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeCtx(array $data = [], array $agents = ['a', 'b', 'c']): RouterContext
{
    return new RouterContext(
        runId: 'run-agent-test',
        state: new WorkflowState(data: $data),
        availableAgents: $agents,
    );
}

function testStaticRouterFixedList(): void
{
    $router = new StaticRouter(['b', 'c']);
    $ids = $router->route(makeCtx());
    assertTrue($ids === ['b', 'c'], 'static router should return fixed agent ids');
}

function testStaticRouterFallsBackToAvailable(): void
{
    $router = new StaticRouter([]);
    $ids = $router->route(makeCtx(agents: ['x', 'y']));
    assertTrue($ids === ['x', 'y'], 'empty static list should use availableAgents');
}

function testRuleRouterCallable(): void
{
    $router = new RuleRouter([
        'a' => static fn (WorkflowState $s): bool => (bool) $s->get('useA'),
        'b' => static fn (WorkflowState $s): bool => (bool) $s->get('useB'),
    ]);
    $ids = $router->route(makeCtx(['useA' => false, 'useB' => true]));
    assertTrue($ids === ['b'], 'rule router should select matching agents');
}

function testWeightedRouterAlwaysSelectsWeightOne(): void
{
    $router = new WeightedRouter(['must' => 1.0, 'never' => 0.0]);
    $ids = $router->route(makeCtx());
    assertTrue(in_array('must', $ids, true), 'weight >= 1 must be selected');
    assertTrue(!in_array('never', $ids, true), 'weight 0 must not be selected');
}

function testCostAwareRouterPicksCheapestInBudget(): void
{
    $router = new CostAwareRouter([
        'premium' => 0.05,
        'cheap' => 0.001,
    ], budgetUsd: 1.0);

    $ids = $router->route(makeCtx(['estimatedTokens' => 1000]));
    assertTrue($ids === ['cheap'], 'should pick lowest cost agent within budget');
}

function testCostAwareRouterFallbackWhenOverBudget(): void
{
    $router = new CostAwareRouter([
        'only' => 100.0,
    ], budgetUsd: 0.01);

    $ids = $router->route(makeCtx(['estimatedTokens' => 10000]));
    assertTrue($ids === ['only'], 'over-budget should fallback to first agent');
}

function testRoundRobinCycles(): void
{
    $router = new RoundRobinRouter(['a', 'b', 'c']);
    assertTrue($router->route(makeCtx()) === ['a'], 'round 1');
    assertTrue($router->route(makeCtx()) === ['b'], 'round 2');
    assertTrue($router->route(makeCtx()) === ['c'], 'round 3');
    assertTrue($router->route(makeCtx()) === ['a'], 'round 4 wraps');
}

function testAgentSchedulerRunsTasksAndWritesOutputs(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $ctx = makeCtx();

    $results = $scheduler->runParallel($ctx, [
        'a' => static fn (): string => 'out-a',
        'b' => static fn (): string => 'out-b',
        'c' => static fn (): string => 'out-c',
    ], new StaticRouter(['a', 'c']));

    assertTrue(($results['a'] ?? null) === 'out-a', 'result a');
    assertTrue(($results['c'] ?? null) === 'out-c', 'result c');
    assertTrue(!array_key_exists('b', $results), 'b should not run');
    assertTrue($ctx->state->agentOutput('a') === 'out-a', 'state agentOutput a');
    assertTrue($ctx->state->agentOutput('c') === 'out-c', 'state agentOutput c');
}

function testAgentSchedulerCapturesTaskErrors(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $ctx = makeCtx();

    $results = $scheduler->runParallel($ctx, [
        'a' => static function (): string {
            throw new RuntimeException('boom');
        },
    ], new StaticRouter(['a']));

    assertTrue(is_array($results['a'] ?? null), 'error should be array payload');
    assertTrue(($results['a']['error'] ?? '') === 'boom', 'error message preserved');
    assertTrue(($results['a']['agentId'] ?? '') === 'a', 'agentId preserved');
}

function testAgentParallelNodeUsesWorkflowDefaultTimeout(): void
{
    $default = WorkflowConfig::load()->defaultNodeTimeoutSeconds();
    $node = new AgentParallelNode(
        'parallel',
        new AgentScheduler(new NeuronFactory()),
        new StaticRouter(['a']),
        ['a' => static fn (): string => 'ok'],
    );

    assertTrue($node->configuredTimeoutSeconds() === 0, 'node defers to workflow default');
    assertTrue($default > 0, 'workflow default timeout configured');
}

$tests = [
    'static router fixed' => 'testStaticRouterFixedList',
    'static router available fallback' => 'testStaticRouterFallsBackToAvailable',
    'rule router callable' => 'testRuleRouterCallable',
    'weighted router extremes' => 'testWeightedRouterAlwaysSelectsWeightOne',
    'cost aware cheapest' => 'testCostAwareRouterPicksCheapestInBudget',
    'cost aware fallback' => 'testCostAwareRouterFallbackWhenOverBudget',
    'round robin cycle' => 'testRoundRobinCycles',
    'scheduler outputs' => 'testAgentSchedulerRunsTasksAndWritesOutputs',
    'scheduler errors' => 'testAgentSchedulerCapturesTaskErrors',
    'agent parallel default timeout' => 'testAgentParallelNodeUsesWorkflowDefaultTimeout',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Agent module tests passed.\n";
