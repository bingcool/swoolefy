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

namespace PHPUintTest\Unit\Support\Agent;

use RuntimeException;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\CostAwareRouter;
use Swoolefy\Support\Agent\Router\RoundRobinRouter;
use Swoolefy\Support\Agent\Router\RuleRouter;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\Router\WeightedRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\AI\Node\AgentParallelNode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use PHPUintTest\TestCase;

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
 * 说明：任务闭包直接返回字符串，不启动真实 Neuron Agent / LLM。
 */
final class AgentModuleTest extends TestCase
{
    /**
     * 构造 RouterContext（固定 runId，可注入 state 数据与 availableAgents）。
     *
     * @param array<string, mixed> $data WorkflowState 初始数据
     * @param list<string> $agents 可用 agent id
     */
    private function makeCtx(array $data = [], array $agents = ['a', 'b', 'c']): RouterContext
    {
        return new RouterContext(
            runId: 'run-agent-test',
            state: new WorkflowState(data: $data),
            availableAgents: $agents,
        );
    }

    /**
     * StaticRouter 返回构造时给定的固定 agent id 列表。
     */
    public function testStaticRouterFixedList(): void
    {
        $router = new StaticRouter(['b', 'c']);
        $ids = $router->route($this->makeCtx());
        $this->assertTrue($ids === ['b', 'c'], 'static router should return fixed agent ids');
    }

    /**
     * Static 列表为空时，回退为 context.availableAgents（便于「全员并行」默认）。
     */
    public function testStaticRouterFallsBackToAvailable(): void
    {
        $router = new StaticRouter([]);
        $ids = $router->route($this->makeCtx(agents: ['x', 'y']));
        $this->assertTrue($ids === ['x', 'y'], 'empty static list should use availableAgents');
    }

    /**
     * RuleRouter：仅 callable 返回 true 的 agent 入选（按 state 字段）。
     */
    public function testRuleRouterCallable(): void
    {
        $router = new RuleRouter([
            'a' => static fn (WorkflowState $s): bool => (bool) $s->get('useA'),
            'b' => static fn (WorkflowState $s): bool => (bool) $s->get('useB'),
        ]);
        $ids = $router->route($this->makeCtx(['useA' => false, 'useB' => true]));
        $this->assertTrue($ids === ['b'], 'rule router should select matching agents');
    }

    /**
     * WeightedRouter：weight≥1 必选，0 永不选（边界，避免随机抖动）。
     */
    public function testWeightedRouterAlwaysSelectsWeightOne(): void
    {
        $router = new WeightedRouter(['must' => 1.0, 'never' => 0.0]);
        $ids = $router->route($this->makeCtx());
        $this->assertTrue(in_array('must', $ids, true), 'weight >= 1 must be selected');
        $this->assertTrue(!in_array('never', $ids, true), 'weight 0 must not be selected');
    }

    /**
     * CostAware：按 estimatedTokens × 单价，在 budget 内选最便宜的。
     */
    public function testCostAwareRouterPicksCheapestInBudget(): void
    {
        $router = new CostAwareRouter([
            'premium' => 0.05,
            'cheap' => 0.001,
        ], budgetUsd: 1.0);

        $ids = $router->route($this->makeCtx(['estimatedTokens' => 1000]));
        $this->assertTrue($ids === ['cheap'], 'should pick lowest cost agent within budget');
    }

    /**
     * 全部超预算时 fallback 到配置中的第一个 agent，避免路由空结果。
     */
    public function testCostAwareRouterFallbackWhenOverBudget(): void
    {
        $router = new CostAwareRouter([
            'only' => 100.0,
        ], budgetUsd: 0.01);

        $ids = $router->route($this->makeCtx(['estimatedTokens' => 10000]));
        $this->assertTrue($ids === ['only'], 'over-budget should fallback to first agent');
    }

    /**
     * RoundRobin 在同一 state 上连续 route：a→b→c→a。
     */
    public function testRoundRobinCycles(): void
    {
        $router = new RoundRobinRouter(['a', 'b', 'c']);
        $ctx = $this->makeCtx();
        $this->assertTrue($router->route($ctx) === ['a'], 'round 1');
        $this->assertTrue($router->route($ctx) === ['b'], 'round 2');
        $this->assertTrue($router->route($ctx) === ['c'], 'round 3');
        $this->assertTrue($router->route($ctx) === ['a'], 'round 4 wraps');
    }

    /**
     * RoundRobin cursor 存在 WorkflowState：不同 run 的 state 互不影响起点。
     */
    public function testRoundRobinCursorIsScopedToWorkflowState(): void
    {
        $router = new RoundRobinRouter(['a', 'b', 'c']);
        $runA = $this->makeCtx();
        $runB = $this->makeCtx();

        $this->assertTrue($router->route($runA) === ['a'], 'run A first route');
        $this->assertTrue($router->route($runA) === ['b'], 'run A second route');
        $this->assertTrue($router->route($runB) === ['a'], 'run B starts from first route');
    }

    /**
     * runParallel：只执行路由选中的 a/c；结果写入返回值与 state.agentOutput。
     */
    public function testAgentSchedulerRunsTasksAndWritesOutputs(): void
    {
        $scheduler = new AgentScheduler(new NeuronFactory());
        $ctx = $this->makeCtx();

        $results = $scheduler->runParallel($ctx, [
            'a' => static fn (): string => 'out-a',
            'b' => static fn (): string => 'out-b',
            'c' => static fn (): string => 'out-c',
        ], new StaticRouter(['a', 'c']));

        $this->assertTrue(($results['a'] ?? null) === 'out-a', 'result a');
        $this->assertTrue(($results['c'] ?? null) === 'out-c', 'result c');
        $this->assertTrue(!array_key_exists('b', $results), 'b should not run');
        $this->assertTrue($ctx->state->agentOutput('a') === 'out-a', 'state agentOutput a');
        $this->assertTrue($ctx->state->agentOutput('c') === 'out-c', 'state agentOutput c');
    }

    /**
     * 默认 failFast=false：任务抛错被收成 array{error, agentId}，不向上抛。
     */
    public function testAgentSchedulerCapturesTaskErrors(): void
    {
        $scheduler = new AgentScheduler(new NeuronFactory());
        $ctx = $this->makeCtx();

        $results = $scheduler->runParallel($ctx, [
            'a' => static function (): string {
                throw new RuntimeException('boom');
            },
        ], new StaticRouter(['a']));

        $this->assertTrue(is_array($results['a'] ?? null), 'error should be array payload');
        $this->assertTrue(($results['a']['error'] ?? '') === 'boom', 'error message preserved');
        $this->assertTrue(($results['a']['agentId'] ?? '') === 'a', 'agentId preserved');
    }

    /**
     * 路由选出的 id 在 tasks map 中不存在时抛 WorkflowException（含 ghost）。
     */
    public function testAgentSchedulerRejectsUnknownSelectedIds(): void
    {
        $scheduler = new AgentScheduler(new NeuronFactory());
        $ctx = $this->makeCtx(agents: ['a', 'b']);

        try {
            $scheduler->runParallel(
                $ctx,
                [
                    'a' => static fn (): string => 'out-a',
                ],
                new StaticRouter(['ghost', 'missing']),
            );
            $this->assertTrue(false, 'should throw when router ids miss tasks');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'no matching tasks'), 'unknown ids message');
            $this->assertTrue(str_contains($e->getMessage(), 'ghost'), 'includes ghost id');
        }
    }

    /**
     * AgentParallelNode 透传 Scheduler「路由缺任务」错误，不静默空跑。
     */
    public function testAgentParallelNodeFailsWhenRouterMissesTasks(): void
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
            $this->assertTrue(false, 'should fail when selected agents miss tasks');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'no matching tasks'), 'parallel node surfaces scheduler error');
        }
    }

    /**
     * 节点未配置超时（0）时，RouterContext.timeoutSeconds 取自 RunContext（引擎默认）。
     */
    public function testAgentParallelNodeUsesEngineTimeoutFromRunContext(): void
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

        $this->assertTrue($node->configuredTimeoutSeconds() === 0, 'node defers to engine timeout');
        $this->assertTrue($capturedTimeout === 88.0, 'scheduler receives engine-resolved timeout');
    }

    /**
     * 节点构造显式 timeout=45 时，覆盖 RunContext 上的 88。
     */
    public function testAgentParallelNodeExplicitTimeoutOverridesRunContext(): void
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

        $this->assertTrue($capturedTimeout === 45.0, 'node-level timeout wins over run context');
    }
}
