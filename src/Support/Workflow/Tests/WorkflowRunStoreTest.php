<?php

declare(strict_types=1);

/**
 * RunStore 配置与 memory / redis / db 驱动测试。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowRunStoreTest.php
 */

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;

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

/** @return array<string, mixed> */
function sampleRunStoresConfig(string $default = WorkflowRunStoreName::MEMORY): array
{
    return [
        'workflow' => [
            'default_run_store' => $default,
            'condition_evaluator' => 'symfony',
            'run_stores' => [
                WorkflowRunStoreName::MEMORY => [],
                WorkflowRunStoreName::REDIS => [
                    'component' => 'redis',
                    'prefix' => 'workflow:run:test:',
                    'ttl' => 3600,
                ],
                WorkflowRunStoreName::DB => [
                    'component' => 'db',
                    'table' => 'workflow_runs',
                ],
            ],
        ],
    ];
}

function registerOrderWorkflow(WorkflowRegistry $registry): void
{
    $registry->register('order_processing', static fn () => OrderProcessingWorkflow::definition(
        static function (): OrderDecisionDto {
            $dto = new OrderDecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.95;
            $dto->reason = 'run store test';

            return $dto;
        },
    ));
}

function makeEngine($runStore): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $runStore,
    );
}

function testRunStoreNameConstants(): void
{
    assertTrue(WorkflowRunStoreName::MEMORY === 'memory', 'MEMORY');
    assertTrue(WorkflowRunStoreName::REDIS === 'redis', 'REDIS');
    assertTrue(WorkflowRunStoreName::DB === 'db', 'DB');
    assertTrue(WorkflowRunStoreName::all() === [
        WorkflowRunStoreName::MEMORY,
        WorkflowRunStoreName::REDIS,
        WorkflowRunStoreName::DB,
    ], 'all()');
    assertTrue(WorkflowRunStoreName::isSupported(WorkflowRunStoreName::DB), 'supported db');
    assertTrue(!WorkflowRunStoreName::isSupported('mongo'), 'unsupported');
    pass('run store name constants');
}

function testWorkflowConfigParsesRunStores(): void
{
    $config = WorkflowConfig::fromArray(sampleRunStoresConfig(WorkflowRunStoreName::DB));

    assertTrue($config->defaultRunStoreAlias() === WorkflowRunStoreName::DB, 'default alias');
    assertTrue($config->runStoreDriver() === WorkflowRunStoreName::DB, 'default driver');
    assertTrue($config->runStoreDriver(WorkflowRunStoreName::REDIS) === WorkflowRunStoreName::REDIS, 'redis driver');
    assertTrue($config->hasRunStoreAlias(WorkflowRunStoreName::MEMORY), 'has memory');
    assertTrue($config->hasRunStoreAlias(WorkflowRunStoreName::REDIS), 'has redis');
    assertTrue($config->hasRunStoreAlias(WorkflowRunStoreName::DB), 'has db');
    assertTrue($config->redisPrefix(WorkflowRunStoreName::REDIS) === 'workflow:run:test:', 'redis prefix');
    assertTrue($config->redisTtl(WorkflowRunStoreName::REDIS) === 3600, 'redis ttl');
    assertTrue($config->dbTable(WorkflowRunStoreName::DB) === 'workflow_runs', 'db table');
    assertTrue($config->dbComponent(WorkflowRunStoreName::DB) === WorkflowRunStoreName::DB, 'db component');
    pass('workflow config parses run_stores');
}

function testWorkflowConfigCustomAliasWithDriver(): void
{
    $config = WorkflowConfig::fromArray([
        'workflow' => [
            'default_run_store' => 'primary_db',
            'run_stores' => [
                'primary_db' => [
                    'driver' => WorkflowRunStoreName::DB,
                    'component' => 'db',
                    'table' => 'wf_runs_prod',
                ],
                'cache_redis' => [
                    'driver' => WorkflowRunStoreName::REDIS,
                    'component' => 'redis',
                    'prefix' => 'wf:',
                    'ttl' => 60,
                ],
            ],
        ],
    ]);

    assertTrue($config->defaultRunStoreAlias() === 'primary_db', 'custom default alias');
    assertTrue($config->runStoreDriver('primary_db') === WorkflowRunStoreName::DB, 'custom db driver');
    assertTrue($config->runStoreDriver('cache_redis') === WorkflowRunStoreName::REDIS, 'custom redis driver');
    assertTrue($config->dbTable('primary_db') === 'wf_runs_prod', 'custom table');
    pass('workflow config custom alias driver');
}

function testFactoryBuildsMemoryRunStore(): void
{
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray(sampleRunStoresConfig(WorkflowRunStoreName::MEMORY));
    $store = WorkflowComponentFactory::runStore($registry, $config);
    assertTrue($store instanceof InMemoryRunStore, 'memory instance');

    $store2 = WorkflowComponentFactory::runStore($registry, $config, WorkflowRunStoreName::MEMORY);
    assertTrue($store2 instanceof InMemoryRunStore, 'explicit memory alias');
    pass('factory builds memory run store');
}

function testFactoryUnsupportedDriverThrows(): void
{
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray([
        'workflow' => [
            'default_run_store' => 'weird',
            'run_stores' => [
                'weird' => ['driver' => 'mongo'],
            ],
        ],
    ]);

    try {
        WorkflowComponentFactory::runStore($registry, $config);
        assertTrue(false, 'should throw');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'Unsupported'), 'unsupported message');
    }
    pass('factory unsupported driver throws');
}

function testFactoryRedisRequiresApplicationContext(): void
{
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray(sampleRunStoresConfig(WorkflowRunStoreName::REDIS));

    try {
        WorkflowComponentFactory::runStore($registry, $config, WorkflowRunStoreName::REDIS);
        assertTrue(false, 'should throw without app');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'Application context'), 'redis needs app');
    }
    pass('factory redis requires application context');
}

function testFactoryDbRequiresApplicationContext(): void
{
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray(sampleRunStoresConfig(WorkflowRunStoreName::DB));

    try {
        WorkflowComponentFactory::runStore($registry, $config, WorkflowRunStoreName::DB);
        assertTrue(false, 'should throw without app');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'Application context'), 'db needs app');
    }
    pass('factory db requires application context');
}

function testMemoryRunStorePersistence(): void
{
    $registry = new WorkflowRegistry();
    registerOrderWorkflow($registry);
    $store = new InMemoryRunStore();
    $engine = makeEngine($store);

    $compiled = WorkflowComponentFactory::compiler(
        WorkflowConfig::fromArray(sampleRunStoresConfig()),
    )->compile($registry->definition('order_processing'));

    $runId = $engine->start($compiled, ['orderId' => 'ORD-MEM-1', 'amount' => 1]);
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'memory completed');

    $engine2 = makeEngine($store);
    $restored = $engine2->getRun($runId);
    assertTrue($restored->state->get('orderId') === 'ORD-MEM-1', 'memory shared store');
    pass('memory run store persistence');
}

function testDbRunStorePersistenceAndListWaiting(): void
{
    $registry = new WorkflowRegistry();
    registerOrderWorkflow($registry);

    $registry->register('hitl_demo', static fn () => WorkflowDefinition::create('hitl_demo', '1.0.0')
        ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success(['ok' => true])))
        ->addNode('pause', new PauseNode('pause', [
            'assignee' => 'ops-team',
            'title' => 'Need review',
        ]))
        ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
        ->addEdge('start', 'pause')
        ->addEdge('pause', 'done'));

    $pdo = new PDO('sqlite::memory:');
    $store = new DbRunStore($pdo, $registry, 'workflow_runs', autoMigrate: true);
    $engine = makeEngine($store);

    $config = WorkflowConfig::fromArray(sampleRunStoresConfig());
    $compiler = WorkflowComponentFactory::compiler($config);

    // completed run
    $completedId = $engine->start(
        $compiler->compile($registry->definition('order_processing')),
        ['orderId' => 'ORD-DB-2', 'amount' => 2],
    );
    assertTrue($engine->getRun($completedId)->status === RunStatus::COMPLETED, 'db completed');

    // waiting run
    $waitingId = $engine->start(
        $compiler->compile($registry->definition('hitl_demo')),
        [],
    );
    $waiting = $engine->getRun($waitingId);
    assertTrue($waiting->status === RunStatus::WAITING, 'db waiting');

    $tasks = $store->listWaiting('ops-team');
    assertTrue(count($tasks) === 1, 'listWaiting assignee');
    assertTrue($tasks[0]->runId === $waitingId, 'waiting run id');

    $allWaiting = $store->listWaiting();
    assertTrue(count($allWaiting) >= 1, 'listWaiting all');

    // cross-engine restore
    $engine2 = makeEngine($store);
    assertTrue($engine2->getRun($completedId)->state->get('orderId') === 'ORD-DB-2', 'db restore');
    assertTrue($engine2->getRun($waitingId)->status === RunStatus::WAITING, 'db waiting restore');

    $engine2->resume($waitingId, ['approved' => true]);
    assertTrue($engine2->getRun($waitingId)->status === RunStatus::COMPLETED, 'db resume completed');
    assertTrue($store->listWaiting('ops-team') === [], 'no waiting after resume');

    pass('db run store persistence and listWaiting');
}

function testDbRunStoreUpsertIdempotent(): void
{
    $registry = new WorkflowRegistry();
    registerOrderWorkflow($registry);
    $pdo = new PDO('sqlite::memory:');
    $store = new DbRunStore($pdo, $registry, 'workflow_runs', autoMigrate: true);
    $engine = makeEngine($store);
    $compiled = WorkflowComponentFactory::compiler(
        WorkflowConfig::fromArray(sampleRunStoresConfig()),
    )->compile($registry->definition('order_processing'));

    $runId = $engine->start($compiled, ['orderId' => 'ORD-DB-UPSERT', 'amount' => 3]);
    $run = $engine->getRun($runId);
    $store->save($run);
    $store->save($run);

    $count = (int) $pdo->query('SELECT COUNT(*) FROM workflow_runs')->fetchColumn();
    assertTrue($count === 1, 'upsert keeps single row');
    pass('db run store upsert idempotent');
}

$tests = [
    'run store name constants' => 'testRunStoreNameConstants',
    'workflow config parses run_stores' => 'testWorkflowConfigParsesRunStores',
    'workflow config custom alias driver' => 'testWorkflowConfigCustomAliasWithDriver',
    'factory builds memory run store' => 'testFactoryBuildsMemoryRunStore',
    'factory unsupported driver throws' => 'testFactoryUnsupportedDriverThrows',
    'factory redis requires application context' => 'testFactoryRedisRequiresApplicationContext',
    'factory db requires application context' => 'testFactoryDbRequiresApplicationContext',
    'memory run store persistence' => 'testMemoryRunStorePersistence',
    'db run store persistence and listWaiting' => 'testDbRunStorePersistenceAndListWaiting',
    'db run store upsert idempotent' => 'testDbRunStoreUpsertIdempotent',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} workflow run store tests passed.\n";
