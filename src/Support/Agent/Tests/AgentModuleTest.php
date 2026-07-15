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
 * Agent 模块回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | StaticRouter | 固定列表；空列表回退 availableAgents |
 * | RuleRouter | callable 按 state 选中 agent |
 * | WeightedRouter | weight≥1 必选、0 不选 |
 * | CostAwareRouter | 预算内选最便宜；超预算 fallback |
 * | RoundRobinRouter | 轮转；cursor 按 WorkflowState 隔离 |
 * | AgentScheduler | 只跑路由选中任务；写 agentOutputs；错误载荷；未知 id 拒绝 |
 * | AgentParallelNode | 路由缺任务失败；引擎超时 vs 节点显式超时 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Agent/Tests/AgentModuleTest.php
 * # 或
 * composer test:agent
 * ```
 *
 * 说明：任务闭包直接返回字符串，不启动真实 Neuron Agent / LLM。
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
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\State\WorkflowState;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 构造 RouterContext（固定 runId，可注入 state 数据与 availableAgents）。
 *
 * @param array<string, mixed> $data WorkflowState 初始数据
 * @param list<string> $agents 可用 agent id
 */
function makeCtx(array $data = [], array $agents = ['a', 'b', 'c']): RouterContext
{
    return new RouterContext(
        runId: 'run-agent-test',
        state: new WorkflowState(data: $data),
        availableAgents: $agents,
    );
}

// ---------------------------------------------------------------------------
// Routers
// ---------------------------------------------------------------------------

/**
 * StaticRouter 返回构造时给定的固定 agent id 列表。
 */
function testStaticRouterFixedList(): void
{
    $router = new StaticRouter(['b', 'c']);
    $ids = $router->route(makeCtx());
    assertTrue($ids === ['b', 'c'], 'static router should return fixed agent ids');
}

/**
 * Static 列表为空时，回退为 context.availableAgents（便于「全员并行」默认）。
 */
function testStaticRouterFallsBackToAvailable(): void
{
    $router = new StaticRouter([]);
    $ids = $router->route(makeCtx(agents: ['x', 'y']));
    assertTrue($ids === ['x', 'y'], 'empty static list should use availableAgents');
}

/**
 * RuleRouter：仅 callable 返回 true 的 agent 入选（按 state 字段）。
 */
function testRuleRouterCallable(): void
{
    $router = new RuleRouter([
        'a' => static fn (WorkflowState $s): bool => (bool) $s->get('useA'),
        'b' => static fn (WorkflowState $s): bool => (bool) $s->get('useB'),
    ]);
    $ids = $router->route(makeCtx(['useA' => false, 'useB' => true]));
    assertTrue($ids === ['b'], 'rule router should select matching agents');
}

/**
 * WeightedRouter：weight≥1 必选，0 永不选（边界，避免随机抖动）。
 */
function testWeightedRouterAlwaysSelectsWeightOne(): void
{
    $router = new WeightedRouter(['must' => 1.0, 'never' => 0.0]);
    $ids = $router->route(makeCtx());
    assertTrue(in_array('must', $ids, true), 'weight >= 1 must be selected');
    assertTrue(!in_array('never', $ids, true), 'weight 0 must not be selected');
}

/**
 * CostAware：按 estimatedTokens × 单价，在 budget 内选最便宜的。
 */
function testCostAwareRouterPicksCheapestInBudget(): void
{
    $router = new CostAwareRouter([
        'premium' => 0.05,
        'cheap' => 0.001,
    ], budgetUsd: 1.0);

    $ids = $router->route(makeCtx(['estimatedTokens' => 1000]));
    assertTrue($ids === ['cheap'], 'should pick lowest cost agent within budget');
}

/**
 * 全部超预算时 fallback 到配置中的第一个 agent，避免路由空结果。
 */
function testCostAwareRouterFallbackWhenOverBudget(): void
{
    $router = new CostAwareRouter([
        'only' => 100.0,
    ], budgetUsd: 0.01);

    $ids = $router->route(makeCtx(['estimatedTokens' => 10000]));
    assertTrue($ids === ['only'], 'over-budget should fallback to first agent');
}

/**
 * RoundRobin 在同一 state 上连续 route：a→b→c→a。
 */
function testRoundRobinCycles(): void
{
    $router = new RoundRobinRouter(['a', 'b', 'c']);
    $ctx = makeCtx();
    assertTrue($router->route($ctx) === ['a'], 'round 1');
    assertTrue($router->route($ctx) === ['b'], 'round 2');
    assertTrue($router->route($ctx) === ['c'], 'round 3');
    assertTrue($router->route($ctx) === ['a'], 'round 4 wraps');
}

/**
 * RoundRobin cursor 存在 WorkflowState：不同 run 的 state 互不影响起点。
 */
function testRoundRobinCursorIsScopedToWorkflowState(): void
{
    $router = new RoundRobinRouter(['a', 'b', 'c']);
    $runA = makeCtx();
    $runB = makeCtx();

    assertTrue($router->route($runA) === ['a'], 'run A first route');
    assertTrue($router->route($runA) === ['b'], 'run A second route');
    assertTrue($router->route($runB) === ['a'], 'run B starts from first route');
}

// ---------------------------------------------------------------------------
// AgentScheduler
// ---------------------------------------------------------------------------

/**
 * runParallel：只执行路由选中的 a/c；结果写入返回值与 state.agentOutput。
 */
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

/**
 * 默认 failFast=false：任务抛错被收成 array{error, agentId}，不向上抛。
 */
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

/**
 * 路由选出的 id 在 tasks map 中不存在时抛 WorkflowException（含 ghost）。
 */
function testAgentSchedulerRejectsUnknownSelectedIds(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $ctx = makeCtx(agents: ['a', 'b']);

    try {
        $scheduler->runParallel(
            $ctx,
            [
                'a' => static fn (): string => 'out-a',
            ],
            new StaticRouter(['ghost', 'missing']),
        );
        assertTrue(false, 'should throw when router ids miss tasks');
    } catch (\Swoolefy\Support\Workflow\Exception\WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'no matching tasks'), 'unknown ids message');
        assertTrue(str_contains($e->getMessage(), 'ghost'), 'includes ghost id');
    }
}

// ---------------------------------------------------------------------------
// AgentParallelNode
// ---------------------------------------------------------------------------

/**
 * AgentParallelNode 透传 Scheduler「路由缺任务」错误，不静默空跑。
 */
function testAgentParallelNodeFailsWhenRouterMissesTasks(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $node = new AgentParallelNode(
        'parallel',
        $scheduler,
        new StaticRouter(['ghost']),
        [
            'a' => static fn (): string => 'ok',
        ],
    );

    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')->addNode('parallel', $node),
    );
    $state = WorkflowState::fromInput([], []);

    try {
        $node->execute(new RunContext('run_1', $compiled, 1, [], 30.0), $state);
        assertTrue(false, 'should fail when selected agents miss tasks');
    } catch (\Swoolefy\Support\Workflow\Exception\WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'no matching tasks'), 'parallel node surfaces scheduler error');
    }
}

/**
 * 节点未配置超时（0）时，RouterContext.timeoutSeconds 取自 RunContext（引擎默认）。
 */
function testAgentParallelNodeUsesEngineTimeoutFromRunContext(): void
{
    $capturedTimeout = null;
    $scheduler = new AgentScheduler(new NeuronFactory());
    $node = new AgentParallelNode(
        'parallel',
        $scheduler,
        new StaticRouter(['a']),
        [
            'a' => static function (RouterContext $ctx) use (&$capturedTimeout): string {
                $capturedTimeout = $ctx->timeoutSeconds;

                return 'ok';
            },
        ],
    );

    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')->addNode('parallel', $node),
    );
    $state = WorkflowState::fromInput([], []);
    $node->execute(new RunContext('run_1', $compiled, 1, [], 88.0), $state);

    assertTrue($node->configuredTimeoutSeconds() === 0, 'node defers to engine timeout');
    assertTrue($capturedTimeout === 88.0, 'scheduler receives engine-resolved timeout');
}

/**
 * 节点构造显式 timeout=45 时，覆盖 RunContext 上的 88。
 */
function testAgentParallelNodeExplicitTimeoutOverridesRunContext(): void
{
    $capturedTimeout = null;
    $scheduler = new AgentScheduler(new NeuronFactory());
    $node = new AgentParallelNode(
        'parallel',
        $scheduler,
        new StaticRouter(['a']),
        [
            'a' => static function (RouterContext $ctx) use (&$capturedTimeout): string {
                $capturedTimeout = $ctx->timeoutSeconds;

                return 'ok';
            },
        ],
        45,
    );

    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')->addNode('parallel', $node),
    );
    $state = WorkflowState::fromInput([], []);
    $node->execute(new RunContext('run_1', $compiled, 1, [], 88.0), $state);

    assertTrue($capturedTimeout === 45.0, 'node-level timeout wins over run context');
}

$tests = [
    'static router fixed' => 'testStaticRouterFixedList',
    'static router available fallback' => 'testStaticRouterFallsBackToAvailable',
    'rule router callable' => 'testRuleRouterCallable',
    'weighted router extremes' => 'testWeightedRouterAlwaysSelectsWeightOne',
    'cost aware cheapest' => 'testCostAwareRouterPicksCheapestInBudget',
    'cost aware fallback' => 'testCostAwareRouterFallbackWhenOverBudget',
    'round robin cycle' => 'testRoundRobinCycles',
    'round robin state scoped cursor' => 'testRoundRobinCursorIsScopedToWorkflowState',
    'scheduler outputs' => 'testAgentSchedulerRunsTasksAndWritesOutputs',
    'scheduler errors' => 'testAgentSchedulerCapturesTaskErrors',
    'scheduler rejects unknown selected ids' => 'testAgentSchedulerRejectsUnknownSelectedIds',
    'agent parallel fails on unknown router ids' => 'testAgentParallelNodeFailsWhenRouterMissesTasks',
    'agent parallel engine timeout' => 'testAgentParallelNodeUsesEngineTimeoutFromRunContext',
    'agent parallel explicit timeout' => 'testAgentParallelNodeExplicitTimeoutOverridesRunContext',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Agent module tests passed.\n";
