<?php

declare(strict_types=1);

/**
 * Phase A 生产加固测试 —— HITL 鉴权、resume CAS 等。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowHitlAuthTest.php
 */

use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\PauseTaskQueryableInterface;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\RunStoreInterface;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunPresenter;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowHitlAuth;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

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

/**
 * @param array<string, string|null> $vars null 表示临时 unset
 * @param callable(): void $callback
 */
function withHitlEnv(array $vars, callable $callback): void
{
    $previous = [];
    foreach ($vars as $name => $value) {
        $previous[$name] = $_ENV[$name] ?? getenv($name);
        if ($value === null) {
            unset($_ENV[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false || $value === null || $value === '') {
                unset($_ENV[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }
}

final class PauseNodeIdSpyRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public ?string $pauseNodeIdAtCas = null;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function save(WorkflowRun $run): void
    {
        $this->inner->save($run);
    }

    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        $this->pauseNodeIdAtCas = $run->pauseNodeId;

        return $this->inner->saveIfStatus($run, $expectedStatus);
    }

    public function find(string $runId): ?WorkflowRun
    {
        return $this->inner->find($runId);
    }

    public function listWaiting(?string $assignee = null): array
    {
        return $this->inner->listWaiting($assignee);
    }
}

final class RunCompleteCounterPlugin implements WorkflowPluginInterface
{
    public int $count = 0;

    public function name(): string
    {
        return 'run_complete_counter';
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->onRunComplete(function (): void {
            ++$this->count;
        });
    }
}

function hitlConfig(array $overrides = []): WorkflowConfig
{
    return WorkflowConfig::fromArray([
        'workflow' => array_merge([
            'default_run_store' => 'memory',
            'hitl' => [
                'auth_enabled' => true,
                'api_key' => 'secret-key',
                'allowed_roles' => ['operator', 'admin'],
                'require_assignee_match' => true,
            ],
        ], $overrides),
    ]);
}

function testHitlAuthWithValidApiKey(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    $auth->assertAuthorized('secret-key', null);
    pass('hitl auth valid api key');
}

function testHitlAuthWithValidRole(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    $auth->assertAuthorized(null, 'admin');
    pass('hitl auth valid role');
}

function testHitlAuthRejectsMissingCredentials(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    try {
        $auth->assertAuthorized(null, null);
        assertTrue(false, 'should throw');
    } catch (WorkflowPermissionException) {
    }
    pass('hitl auth rejects missing credentials');
}

function testHitlAuthDisabledAllowsAll(): void
{
    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function (): void {
        $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false]]));
        $auth->assertAuthorized(null, null);
    });
    pass('hitl auth disabled allows all');
}

function testHitlAssigneeMatch(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal-team']))
        ->addEdge('start', 'pause'));

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('hitl'));

    $pdo = new PDO('sqlite::memory:');
    WorkflowRunsSchemaInstaller::install($pdo);
    $store = new DbRunStore($pdo, $registry, 'workflow_runs');
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);
    assertTrue($run->status === RunStatus::WAITING, 'waiting');

    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function () use ($run): void {
        $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false, 'require_assignee_match' => true]]));

        try {
            $auth->assertCanResume($run, null, 'wrong-team', null);
            assertTrue(false, 'should throw assignee mismatch');
        } catch (WorkflowPermissionException $e) {
            assertTrue(str_contains($e->getMessage(), 'legal-team'), 'assignee message');
        }

        $auth->assertCanResume($run, null, 'legal-team', null);
    });
    pass('hitl assignee match');
}

function testResolveListAssigneeFilterScopesNonAdminToActor(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());

    assertTrue($auth->resolveListAssigneeFilter(null, 'alice', 'operator') === 'alice', 'non-admin defaults to actor');
    assertTrue($auth->resolveListAssigneeFilter('legal-team', 'alice', 'operator') === 'legal-team', 'query assignee preserved');
    assertTrue($auth->resolveListAssigneeFilter(null, 'alice', WorkflowHitlAuth::ADMIN_ROLE) === null, 'admin sees all');
    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function (): void {
        assertTrue(
            (new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false]])))
                ->resolveListAssigneeFilter(null, 'alice', 'operator') === null,
            'auth disabled sees all',
        );
    });

    pass('resolve list assignee filter');
}

function testListPauseTasksScopesByAssigneeFilter(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl_a', static fn () => WorkflowDefinition::create('hitl_a', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal-team']))
        ->addEdge('start', 'pause'));
    $registry->register('hitl_b', static fn () => WorkflowDefinition::create('hitl_b', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops-team']))
        ->addEdge('start', 'pause'));

    $compiler = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]));
    $store = new InMemoryRunStore();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $engine->start($compiler->compile($registry->definition('hitl_a')), []);
    $engine->start($compiler->compile($registry->definition('hitl_b')), []);

    assertTrue(count($engine->listPauseTasks(null)) === 2, 'no filter returns all');
    assertTrue(count($engine->listPauseTasks('legal-team')) === 1, 'legal-team filter');
    assertTrue(count($engine->listPauseTasks('ops-team')) === 1, 'ops-team filter');
    assertTrue(count($engine->listPauseTasks('unknown')) === 0, 'unknown assignee empty');

    pass('list pause tasks scopes by assignee filter');
}

function testResumeCasPreventsDoubleResume(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
        ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
        ->addEdge('start', 'pause')
        ->addEdge('pause', 'done'));

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('hitl'));

    $store = new InMemoryRunStore();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $runId = $engine->start($compiled, []);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting');

    $store->saveIfStatus($engine->getRun($runId), RunStatus::WAITING);
    $run = $engine->getRun($runId);
    $run->status = RunStatus::RUNNING;
    $run->updatedAt = WorkflowRunTime::now();
    assertTrue($store->saveIfStatus($run, RunStatus::WAITING), 'first cas');

    $run2 = $engine->getRun($runId);
    $run2->status = RunStatus::RUNNING;
    assertTrue(!$store->saveIfStatus($run2, RunStatus::WAITING), 'second cas fails');

    try {
        $engine->resume($runId, ['approved' => true]);
        assertTrue(false, 'resume should fail after cas claim');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'not waiting'), 'resume blocked');
    }

    pass('resume cas prevents double resume');
}

function testDbRunStoreSaveIfStatus(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('x', static fn () => WorkflowDefinition::create('x', '1.0.0')
        ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())));

    $pdo = new PDO('sqlite::memory:');
    WorkflowRunsSchemaInstaller::install($pdo);
    $store = new DbRunStore($pdo, $registry, 'workflow_runs');
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('x'));
    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);

    assertTrue(!$store->saveIfStatus($run, RunStatus::WAITING), 'completed run not waiting');
    $run->status = RunStatus::WAITING;
    assertTrue($store->saveIfStatus($run, RunStatus::COMPLETED), 'cas from completed to waiting');

    pass('db run store saveIfStatus');
}

function testResumeCasPersistClearsPauseNodeId(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
        ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
        ->addEdge('start', 'pause')
        ->addEdge('pause', 'done'));

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('hitl'));

    $inner = new InMemoryRunStore();
    $store = new PauseNodeIdSpyRunStore($inner);
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $runId = $engine->start($compiled, []);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($store->pauseNodeIdAtCas === null, 'cas persist clears pauseNodeId');
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'completed after resume');

    pass('resume cas persist clears pause node id');
}

function testResumeDoesNotFireRunCompleteWhileWaiting(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause1', new PauseNode('pause1', ['assignee' => 'ops']))
        ->addNode('middle', new ClosureNode('middle', static fn () => NodeExecutionResult::success()))
        ->addNode('pause2', new PauseNode('pause2', ['assignee' => 'ops']))
        ->addEdge('start', 'pause1')
        ->addEdge('pause1', 'middle')
        ->addEdge('middle', 'pause2'));

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('hitl'));

    $counter = new RunCompleteCounterPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([$counter]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: new InMemoryRunStore(),
    );

    $runId = $engine->start($compiled, []);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting at pause1');
    assertTrue($counter->count === 0, 'start waiting does not fire run complete');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting at pause2');
    assertTrue($counter->count === 0, 'resume to waiting does not fire run complete');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'completed');
    assertTrue($counter->count === 1, 'run complete fires once after final resume');

    pass('resume run complete aligned with start');
}

function testCancelWaitingFiresRunCompleteAndReleasesRateLimit(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
        ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
        ->addEdge('start', 'pause'));

    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
        ->compile($registry->definition('hitl'));

    $rateLimit = RateLimitPlugin::make(1);
    $counter = new RunCompleteCounterPlugin();
    $engine = new WorkflowEngine(
        plugins: new PluginManager([$rateLimit, $counter]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: new InMemoryRunStore(),
    );

    $runId = $engine->start($compiled, []);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting before cancel');
    assertTrue($rateLimit->activeRuns() === 1, 'waiting run holds rate limit slot');
    assertTrue($counter->count === 0, 'waiting has not completed');

    $engine->cancel($runId);
    assertTrue($engine->getRun($runId)->status === RunStatus::CANCELLED, 'cancelled waiting run');
    assertTrue($rateLimit->activeRuns() === 0, 'cancel releases rate limit slot');
    assertTrue($counter->count === 1, 'cancel waiting fires run complete once');

    pass('cancel waiting releases rate limit');
}

function testWorkflowRunPresenterRedactsDetailsByDefault(): void
{
    $definition = WorkflowDefinition::create('secure_status', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()));
    $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile($definition);
    $state = new \Swoolefy\Support\Workflow\State\WorkflowState([
        'secretInput' => 'should-not-leak',
        'feedback' => ['approved' => true],
    ]);
    $state->setNodeOutput('start', ['secretOutput' => 'hidden']);
    $state->setAgentOutput('agent-a', ['answer' => 'hidden']);

    $run = new WorkflowRun(
        runId: 'run-secure-status',
        compiled: $compiled,
        status: RunStatus::WAITING,
        state: $state,
        createdAt: WorkflowRunTime::now(),
        updatedAt: WorkflowRunTime::now(),
        currentNodeId: 'start',
        pauseNodeId: 'start',
    );

    $summary = WorkflowRunPresenter::toArray($run);
    assertTrue(!array_key_exists('data', $summary), 'summary redacts data');
    assertTrue(!array_key_exists('nodeOutputs', $summary), 'summary redacts node outputs');
    assertTrue(!array_key_exists('agentOutputs', $summary), 'summary redacts agent outputs');
    assertTrue($summary['waiting'] === true, 'summary keeps status metadata');

    $details = WorkflowRunPresenter::toArray($run, includeDetails: true);
    assertTrue(($details['data']['secretInput'] ?? '') === 'should-not-leak', 'details include data');
    assertTrue(($details['nodeOutputs']['start']['secretOutput'] ?? '') === 'hidden', 'details include node output');
    assertTrue(($details['agentOutputs']['agent-a']['answer'] ?? '') === 'hidden', 'details include agent output');

    pass('workflow run presenter redacts details by default');
}

$tests = [
    'hitl auth valid api key' => 'testHitlAuthWithValidApiKey',
    'hitl auth valid role' => 'testHitlAuthWithValidRole',
    'hitl auth rejects missing credentials' => 'testHitlAuthRejectsMissingCredentials',
    'hitl auth disabled allows all' => 'testHitlAuthDisabledAllowsAll',
    'hitl assignee match' => 'testHitlAssigneeMatch',
    'resolve list assignee filter' => 'testResolveListAssigneeFilterScopesNonAdminToActor',
    'list pause tasks scopes by assignee filter' => 'testListPauseTasksScopesByAssigneeFilter',
    'resume cas prevents double resume' => 'testResumeCasPreventsDoubleResume',
    'resume cas persist clears pause node id' => 'testResumeCasPersistClearsPauseNodeId',
    'resume run complete aligned with start' => 'testResumeDoesNotFireRunCompleteWhileWaiting',
    'cancel waiting releases rate limit' => 'testCancelWaitingFiresRunCompleteAndReleasesRateLimit',
    'workflow run presenter redacts details' => 'testWorkflowRunPresenterRedactsDetailsByDefault',
    'db run store saveIfStatus' => 'testDbRunStoreSaveIfStatus',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} workflow HITL / CAS tests passed.\n";
