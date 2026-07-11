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
 * Phase C / P0 生产加固测试 —— URL guard、cancel CAS、Agent 并行失败传播。
 *
 * 运行：composer test:phase-c
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

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

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
    assertTrue(!$store->saveIfStatus($stale, RunStatus::WAITING), 'cas rejects when persisted RUNNING');

    pass('cancel waiting uses cas');
}

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
