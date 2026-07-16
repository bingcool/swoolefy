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
 * RunStore 配置解析与 memory / redis / db 驱动行为测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | WorkflowRunStoreName | 常量、all()、isSupported() |
 * | WorkflowConfig | run_stores 解析、自定义 alias/driver、redis/db 参数 |
 * | WorkflowComponentFactory | memory 构建、不支持 driver 抛错、redis/db 需 ApplicationContext |
 * | InMemoryRunStore | 跨 Engine 共享、run_id 格式 |
 * | DbRunStore | 持久化、listWaiting、resume、upsert 幂等 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowRunStoreTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 通用断言与输出
// ---------------------------------------------------------------------------

/** 断言条件为真，否则抛 RuntimeException 使单测失败。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 在 CLI 输出 [PASS] 标记。 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// 配置与注册夹具
// ---------------------------------------------------------------------------

/**
 * 返回含 memory/redis/db 三套 run_stores 的示例 Workflow 配置数组。
 *
 * @return array<string, mixed>
 */
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

/**
 * 向 Registry 注册 order_processing 工作流（高置信度批准路径），供 RunStore 持久化用例使用。
 */
function registerOrderWorkflow(WorkflowRegistry $registry): void
{
    $registry->register('order_processing', static fn () => OrderProcessingFixtureWorkflow::definition(
        new NeuronFactory(),
        static function (): DecisionDto {
            $dto = new DecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.95;
            $dto->reason = 'run store test';

            return $dto;
        },
    ));
}

/**
 * 构造绑定指定 RunStore 的最小 WorkflowEngine（无插件）。
 */
function makeEngine($runStore): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $runStore,
    );
}

// ---------------------------------------------------------------------------
// WorkflowRunStoreName 与 WorkflowConfig
// ---------------------------------------------------------------------------

/**
 * 验证：WorkflowRunStoreName 常量值、all() 列表与 isSupported() 对合法/非法 driver 的判断。
 */
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

/**
 * 验证：WorkflowConfig::fromArray 正确解析 default_run_store、各 alias 的 driver、redis prefix/ttl、db table。
 */
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

/**
 * 验证：自定义 alias（primary_db、cache_redis）通过 driver 字段映射到 db/redis 驱动及表名。
 *
 * 生产可多租户/多环境使用不同 alias 指向同一 driver 类型。
 */
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

// ---------------------------------------------------------------------------
// WorkflowComponentFactory RunStore 构建
// ---------------------------------------------------------------------------

/**
 * 验证：默认与显式 memory alias 均构建 InMemoryRunStore 实例。
 */
function testFactoryBuildsMemoryRunStore(): void
{
    WorkflowComponentFactory::resetRunStores();
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray(sampleRunStoresConfig(WorkflowRunStoreName::MEMORY));
    $store = WorkflowComponentFactory::runStore($registry, $config);
    assertTrue($store instanceof InMemoryRunStore, 'memory instance');

    $store2 = WorkflowComponentFactory::runStore($registry, $config, WorkflowRunStoreName::MEMORY);
    assertTrue($store2 instanceof InMemoryRunStore, 'explicit memory alias');
    assertTrue($store === $store2, 'same registry+alias must reuse RunStore binding');
    pass('factory builds memory run store');
}

/**
 * 验证：配置不支持的 driver（mongo）时 WorkflowComponentFactory::runStore 抛 WorkflowException。
 */
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

/**
 * 验证：无 ApplicationContext 时构建 redis RunStore 失败，错误信息提及 Application context。
 *
 * Redis 连接须从 Swoolefy 应用容器获取，CLI 单测无上下文。
 */
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

/**
 * 验证：无 ApplicationContext 时构建 db RunStore 失败，错误信息提及 Application context。
 */
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

// ---------------------------------------------------------------------------
// InMemoryRunStore 与 DbRunStore 运行时行为
// ---------------------------------------------------------------------------

/**
 * 验证：InMemoryRunStore 在多个 Engine 间共享；run_id 符合 run_YYYYMMDD_hex 格式。
 */
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
    assertTrue((bool) preg_match('/^run_\d{8}_[a-f0-9]{16}$/', $runId), 'run_id format');
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'memory completed');

    $engine2 = makeEngine($store);
    $restored = $engine2->getRun($runId);
    assertTrue($restored->state->get('orderId') === 'ORD-MEM-1', 'memory shared store');
    pass('memory run store persistence');
}

/**
 * 验证：DbRunStore 持久化 COMPLETED 与 WAITING run；listWaiting 按 assignee 过滤；跨 Engine resume 后无待办。
 */
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
    WorkflowRunsSchemaInstaller::install($pdo);
    $store = new DbRunStore($pdo, $registry, 'workflow_runs');
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

/**
 * 验证：DbRunStore 对同一 run_id 多次 save 为 upsert，表中仅保留一行。
 */
function testDbRunStoreUpsertIdempotent(): void
{
    $registry = new WorkflowRegistry();
    registerOrderWorkflow($registry);
    $pdo = new PDO('sqlite::memory:');
    WorkflowRunsSchemaInstaller::install($pdo);
    $store = new DbRunStore($pdo, $registry, 'workflow_runs');
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

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

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
