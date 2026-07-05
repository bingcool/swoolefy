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
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
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
    $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false]]));
    $auth->assertAuthorized(null, null);
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

    $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false, 'require_assignee_match' => true]]));

    try {
        $auth->assertCanResume($run, null, 'wrong-team', null);
        assertTrue(false, 'should throw assignee mismatch');
    } catch (WorkflowPermissionException $e) {
        assertTrue(str_contains($e->getMessage(), 'legal-team'), 'assignee message');
    }

    $auth->assertCanResume($run, null, 'legal-team', null);
    pass('hitl assignee match');
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

$tests = [
    'hitl auth valid api key' => 'testHitlAuthWithValidApiKey',
    'hitl auth valid role' => 'testHitlAuthWithValidRole',
    'hitl auth rejects missing credentials' => 'testHitlAuthRejectsMissingCredentials',
    'hitl auth disabled allows all' => 'testHitlAuthDisabledAllowsAll',
    'hitl assignee match' => 'testHitlAssigneeMatch',
    'resume cas prevents double resume' => 'testResumeCasPreventsDoubleResume',
    'db run store saveIfStatus' => 'testDbRunStoreSaveIfStatus',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} workflow HITL / CAS tests passed.\n";
