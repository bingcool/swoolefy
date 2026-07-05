<?php

declare(strict_types=1);

/**
 * Phase C / P0 生产加固测试 —— URL guard、cancel CAS、Agent 并行失败传播。
 *
 * 运行：composer test:phase-c
 */

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
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
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

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
    $now = time();
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
    $now = time();
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

    pass('cancel running sets cooperative flag');
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

$tests = [
    'outbound url suffix bypass blocked' => 'testOutboundUrlSuffixBypassBlocked',
    'outbound url require allowlist empty' => 'testOutboundUrlRequireAllowlistEmpty',
    'cancel waiting uses cas' => 'testCancelWaitingUsesCas',
    'cancel waiting success' => 'testCancelWaitingSuccess',
    'cancel running sets cooperative flag' => 'testCancelRunningSetsCooperativeFlag',
    'agent parallel node fails on partial error' => 'testAgentParallelNodeFailsOnPartialError',
    'agent parallel fail fast throws' => 'testAgentParallelFailFastThrows',
];

$passed = 0;
foreach ($tests as $label => $fn) {
    $fn();
    ++$passed;
}

echo "\nAll {$passed} Phase C / P0 tests passed.\n";
