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
 * Phase C / P0 生产加固测试 —— URL 后缀绕过、Cancel CAS、Agent 并行失败传播、协程超时。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | OutboundUrlGuard | 后缀绕过（notopenai.com）拦截；空 allowlist + requireAllowlist fail-closed |
 * | InMemoryRunStore / Engine.cancel | WAITING 取消走 CAS；RUNNING 合作式标志；立即释放 RateLimit 槽位 |
 * | AgentParallelNode / AgentScheduler | 部分失败 → 节点 FAILED；failFast 同步/协程内向上抛 |
 * | GoWaitGroup | 协程内 batch 超时抛 SystemException |
 *
 * ## 运行
 * ```bash
 * php src/Support/Tests/PhaseCProductionTest.php
 * # 或
 * composer test:phase-c
 * ```
 *
 * 说明：含 Swoole 协程的用例在无 swoole 扩展时打印 `[SKIP]` 并跳过，不计入失败。
 * 依赖 {@see SwoolefyTestBootstrap.php} 提供 CLI 下 APP_PATH 等常量。
 */

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Exception\SystemException;
use Swoolefy\Support\AI\Node\AgentParallelNode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Security\OutboundUrlGuard;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\NodeStatus;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/SwoolefyTestBootstrap.php';

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 打印通过标记 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// OutboundUrlGuard：后缀匹配不得被「包含子串」的恶意域名绕过
// ---------------------------------------------------------------------------

/**
 * allowlist 为 openai.com 时：api.openai.com 合法；
 * notopenai.com 不得因 endsWith/含 openai.com 子串而误放行。
 */
function testOutboundUrlSuffixBypassBlocked(): void
{
    $guard = new OutboundUrlGuard(['openai.com'], allowPrivateNetworks: false, requireAllowlist: false);

    $guard->assertAllowed('https://api.openai.com/v1', 'openai');

    try {
        $guard->assertAllowed('https://notopenai.com/v1', 'evil');
        assertTrue(false, 'notopenai.com must not match openai.com');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'allowlist'), 'suffix bypass blocked');
    }

    pass('outbound url suffix bypass blocked');
}

/**
 * requireAllowlist=true 且后缀列表为空时 fail-closed：任何 URL 都拒绝。
 */
function testOutboundUrlRequireAllowlistEmpty(): void
{
    $guard = new OutboundUrlGuard([], allowPrivateNetworks: false, requireAllowlist: true);

    try {
        $guard->assertAllowed('https://api.openai.com/v1', 'openai');
        assertTrue(false, 'empty allowlist should fail-closed');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'required but empty'), 'fail-closed message');
    }

    pass('outbound url require allowlist empty');
}

// ---------------------------------------------------------------------------
// Cancel：CAS 条件写 + WAITING 成功路径 + RUNNING 合作式取消
// ---------------------------------------------------------------------------

/**
 * saveIfStatus 模拟「取消 WAITING」的 CAS：内存中实际仍是 RUNNING 时，
 * 期望状态 WAITING 的写入应失败，避免并发覆盖。
 */
function testCancelWaitingUsesCas(): void
{
    $store = new InMemoryRunStore();
    $now = WorkflowRunTime::now();
    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal'])),
    );
    $run = new WorkflowRun(
        runId: 'run_cas',
        compiled: $compiled,
        status: RunStatus::RUNNING,
        state: WorkflowState::fromInput([], []),
        createdAt: $now,
        updatedAt: $now,
    );
    $store->save($run);

    $stale = $store->find('run_cas');
    assertTrue($stale !== null, 'run loaded');
    $stale->status = RunStatus::CANCELLED;
    // 期望「当前为 WAITING」才允许写成 CANCELLED —— 实际是 RUNNING → CAS 失败
    assertTrue(!$store->saveIfStatus($stale, RunStatus::WAITING), 'cas rejects when persisted RUNNING');

    pass('cancel waiting uses cas');
}

/**
 * 引擎 start 到 PauseNode（WAITING）后 cancel，run 状态应变为 CANCELLED。
 */
function testCancelWaitingSuccess(): void
{
    $store = new InMemoryRunStore();
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal'])));

    $compiled = (new WorkflowCompiler())->compile($registry->definition('hitl'));
    $engine = new WorkflowEngine(
        new PluginManager(),
        new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        $store,
    );

    $runId = $engine->start($compiled, []);
    $engine->cancel($runId);

    assertTrue($engine->getRun($runId)->status === RunStatus::CANCELLED, 'cancelled');

    pass('cancel waiting success');
}

/**
 * RUNNING 取消不立刻改 status（合作式）：打上 `_cancelRequested`，
 * 并提前 fire runComplete（`_runCompleteFired`）以便释放并发槽位等副作用。
 */
function testCancelRunningSetsCooperativeFlag(): void
{
    $store = new InMemoryRunStore();
    $now = WorkflowRunTime::now();
    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')
            ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())),
    );
    $run = new WorkflowRun(
        runId: 'run_test',
        compiled: $compiled,
        status: RunStatus::RUNNING,
        state: WorkflowState::fromInput([], []),
        createdAt: $now,
        updatedAt: $now,
    );
    $store->save($run);

    $engine = new WorkflowEngine(
        new PluginManager(),
        new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        $store,
    );
    $engine->cancel('run_test');

    $fresh = $store->find('run_test');
    assertTrue($fresh !== null, 'run exists');
    assertTrue($fresh->status === RunStatus::RUNNING, 'still running until cooperative stop');
    assertTrue($fresh->state->get('_cancelRequested', false) === true, 'cancel flag set');
    assertTrue($fresh->state->get('_runCompleteFired', false) === true, 'run complete fired early for slot release');

    pass('cancel running sets cooperative flag');
}

/**
 * RUNNING cancel 必须立刻把 RateLimitPlugin 占用的 activeRuns 减回 0，
 * 否则合作式结束前会长期占满并发配额。
 */
function testCancelRunningReleasesRateLimitImmediately(): void
{
    $store = new InMemoryRunStore();
    $now = WorkflowRunTime::now();
    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')
            ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())),
    );

    $rateLimit = RateLimitPlugin::make(1);
    $plugins = new PluginManager([$rateLimit]);
    $engine = new WorkflowEngine(
        $plugins,
        new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        $store,
    );

    $run = new WorkflowRun(
        runId: 'run_rl',
        compiled: $compiled,
        status: RunStatus::RUNNING,
        state: WorkflowState::fromInput([], []),
        createdAt: $now,
        updatedAt: $now,
    );
    $store->save($run);
    $plugins->fireRunStart($run, []);
    assertTrue($rateLimit->activeRuns() === 1, 'slot held while running');

    $engine->cancel('run_rl');
    assertTrue($rateLimit->activeRuns() === 0, 'rate limit released immediately on RUNNING cancel');

    pass('cancel running releases rate limit immediately');
}

// ---------------------------------------------------------------------------
// Agent 并行：部分失败汇总 vs failFast 立即抛出（含协程）
// ---------------------------------------------------------------------------

/**
 * failFast=false：一个 agent 抛错时节点整体 FAILED，output.failedAgents 含失败 agent id。
 */
function testAgentParallelNodeFailsOnPartialError(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $node = new AgentParallelNode(
        'parallel',
        $scheduler,
        new StaticRouter(['a', 'b']),
        [
            'a' => static fn (): string => 'ok',
            'b' => static function (): string {
                throw new RuntimeException('agent-b-failed');
            },
        ],
        failFast: false,
    );

    $compiled = (new WorkflowCompiler())->compile(
        WorkflowDefinition::create('demo', '1.0.0')->addNode('parallel', $node),
    );
    $state = WorkflowState::fromInput([], []);
    $result = $node->execute(new RunContext('run_1', $compiled), $state);

    assertTrue($result->status === NodeStatus::FAILED, 'node failed');
    assertTrue(
        is_array($result->output) && in_array('b', $result->output['failedAgents'] ?? [], true),
        'failedAgents contains b',
    );

    pass('agent parallel node fails on partial error');
}

/**
 * failFast=true（同步路径）：首个 agent 异常原样向上抛，不等待其它任务。
 */
function testAgentParallelFailFastThrows(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $ctx = new \Swoolefy\Support\Agent\RouterContext(
        runId: 'run_1',
        state: WorkflowState::fromInput([], []),
        availableAgents: ['a'],
        timeoutSeconds: 5.0,
    );

    try {
        $scheduler->runParallel($ctx, [
            'a' => static function (): void {
                throw new RuntimeException('boom');
            },
        ], new StaticRouter(['a']), failFast: true);
        assertTrue(false, 'should throw');
    } catch (RuntimeException $e) {
        assertTrue($e->getMessage() === 'boom', 'failFast propagates');
    }

    pass('agent parallel fail fast throws');
}

/**
 * failFast=true 在 Swoole\Coroutine\run 内同样能把异常传到外层 catch。
 * 无 swoole 扩展时 SKIP。
 */
function testAgentParallelFailFastInCoroutine(): void
{
    if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
        echo "[SKIP] agent parallel fail fast in coroutine (no swoole)\n";

        return;
    }

    $propagated = false;
    \Swoole\Coroutine\run(static function () use (&$propagated): void {
        $scheduler = new AgentScheduler(new NeuronFactory());
        $ctx = new RouterContext(
            runId: 'run_coroutine',
            state: WorkflowState::fromInput([], []),
            availableAgents: ['a'],
            timeoutSeconds: 5.0,
        );

        try {
            $scheduler->runParallel($ctx, [
                'a' => static function (): void {
                    throw new RuntimeException('boom-coroutine');
                },
            ], new StaticRouter(['a']), failFast: true);
        } catch (RuntimeException $e) {
            $propagated = $e->getMessage() === 'boom-coroutine';
        }
    });

    assertTrue($propagated, 'failFast propagates inside swoole coroutine');

    pass('agent parallel fail fast in coroutine');
}

/**
 * GoWaitGroup::batchParallelRunWait 超时应抛 SystemException（含 timed out）。
 * 任务 sleep 0.3s、超时 0.05s。无 swoole 时 SKIP。
 */
function testGoWaitGroupTimeoutInCoroutine(): void
{
    if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
        echo "[SKIP] go wait group timeout in coroutine (no swoole)\n";

        return;
    }

    $timedOut = false;
    \Swoole\Coroutine\run(static function () use (&$timedOut): void {
        try {
            GoWaitGroup::batchParallelRunWait([
                'slow' => static function (): string {
                    \Swoole\Coroutine::sleep(0.3);

                    return 'done';
                },
            ], 0.05);
        } catch (SystemException $e) {
            $timedOut = str_contains($e->getMessage(), 'timed out');
        }
    });

    assertTrue($timedOut, 'go wait group timeout throws');

    pass('go wait group timeout in coroutine');
}

$tests = [
    'outbound url suffix bypass blocked' => 'testOutboundUrlSuffixBypassBlocked',
    'outbound url require allowlist empty' => 'testOutboundUrlRequireAllowlistEmpty',
    'cancel waiting uses cas' => 'testCancelWaitingUsesCas',
    'cancel waiting success' => 'testCancelWaitingSuccess',
    'cancel running sets cooperative flag' => 'testCancelRunningSetsCooperativeFlag',
    'cancel running releases rate limit' => 'testCancelRunningReleasesRateLimitImmediately',
    'agent parallel node fails on partial error' => 'testAgentParallelNodeFailsOnPartialError',
    'agent parallel fail fast throws' => 'testAgentParallelFailFastThrows',
    'agent parallel fail fast in coroutine' => 'testAgentParallelFailFastInCoroutine',
    'go wait group timeout in coroutine' => 'testGoWaitGroupTimeoutInCoroutine',
];

$passed = 0;
foreach ($tests as $label => $fn) {
    $fn();
    ++$passed;
}

echo "\nAll {$passed} Phase C / P0 tests passed.\n";
