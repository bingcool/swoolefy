<?php

declare(strict_types=1);

/**
 * Phase 4 工作流回归测试。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowPhase4Test.php
 */

use Swoolefy\Support\Agent\Router\CostAwareRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Mcp\McpProcessLimitException;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Node\RagIngestNode;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Exception\WorkflowRateLimitException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\PermissionPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Knowledge\Support\KnowledgeSeeder;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeEngine(?PluginManager $plugins = null, ?InMemoryRunStore $store = null): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: $plugins ?? new PluginManager([new RetryPlugin()]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        runStore: $store ?? new InMemoryRunStore(),
        events: new StreamWorkflowEventDispatcher(),
    );
}

function testVectorStoreFactoryFileMode(): void
{
    $basePath = sys_get_temp_dir() . '/swoolefy_rag_test';
    $config = \Swoolefy\Support\Neuron\NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => \Swoolefy\Support\Neuron\NeuronAiVectorStoreName::FILE,
            'allow_fake_embeddings' => true,
            'require_tenant_isolation' => false,
            'vector_stores' => [
                \Swoolefy\Support\Neuron\NeuronAiVectorStoreName::FILE => ['path' => $basePath],
            ],
        ],
    ]);
    $factory = new VectorStoreFactory($config, $basePath);
    assertTrue($factory->storeType() === 'file', 'store type file');
    assertTrue($factory->make('test_kb') !== null, 'should create vector store');
}

function testRagIngestAndRetrieve(): void
{
    WorkflowService::reset();
    $pipeline = WorkflowService::ingestionPipeline();
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('ingest_demo')
            ->addNode('ingest', new RagIngestNode('ingest', [
                'knowledgeBase' => 'ingest_kb',
                'sourceKey' => 'documents',
            ], $pipeline)),
    );

    $runId = $engine->start($compiled, [
        'documents' => ['Phase 4 ingest line A', 'Phase 4 ingest line B'],
    ]);
    $run = $engine->getRun($runId);
    assertTrue($run->status === RunStatus::COMPLETED, 'ingest workflow should complete');
    assertTrue(($run->state->get('ingestedCount') ?? 0) === 2, 'should ingest 2 docs');

    $docs = WorkflowService::retrievalService()->retrieve('ingest_kb', 'Phase 4 ingest', 3);
    assertTrue(count($docs) >= 1, 'retrieval should find ingested content');
}

function testRetrievalToolFactory(): void
{
    WorkflowService::reset();
    (new KnowledgeSeeder(WorkflowService::ragFactory()))->seedProductKb();
    $tool = WorkflowService::retrievalToolFactory()->make('product_kb');
    assertTrue($tool->getName() === 'context_retrieval', 'should create Neuron RetrievalTool');
}

function testOrderSagaCompensation(): void
{
    $store = new InMemoryRunStore();
    $engine = makeEngine(store: $store);
    $compiled = WorkflowBootstrap::compiler()->compile(OrderSagaWorkflow::definition());

    try {
        $engine->start($compiled, ['orderId' => 'ord-saga-1']);
        assertTrue(false, 'saga workflow should throw on notify_fail');
    } catch (WorkflowException) {
        // expected
    }

    $runs = $store->all();
    assertTrue(count($runs) >= 1, 'should have saved run');
    $run = $runs[count($runs) - 1];
    assertTrue($run->status === RunStatus::COMPENSATED, 'run should be COMPENSATED after saga');
    assertTrue($run->state->get('paymentStatus') === 'refunded', 'payment should be refunded');
    assertTrue($run->state->get('inventoryReserved') === false, 'inventory should be released');
    assertTrue(in_array('payment', $run->state->get('compensatedNodes') ?? [], true), 'payment should be compensated');
}

function testCostAwareRouter(): void
{
    $router = new CostAwareRouter(['cheap' => 0.001, 'expensive' => 0.05], budgetUsd: 0.01);
    $ctx = new RouterContext('r1', WorkflowState::fromInput(['query' => str_repeat('x', 100)]));
    $ids = $router->route($ctx);
    assertTrue($ids === ['cheap'], 'should pick cheaper agent within budget');
}

function testRateLimitPluginRelease(): void
{
    $plugin = RateLimitPlugin::make(maxConcurrent: 2);
    $engine = makeEngine(new PluginManager([$plugin]));
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('rl')
            ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
    );

    $engine->start($compiled, []);
    assertTrue($plugin->activeRuns() === 0, 'slot released after complete');
}

function testRateLimitExceeded(): void
{
    $plugin = RateLimitPlugin::make(maxConcurrent: 0);
    $engine = makeEngine(new PluginManager([$plugin]));
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('rl0')
            ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
    );

    try {
        $engine->start($compiled, []);
        assertTrue(false, 'should rate limit');
    } catch (WorkflowRateLimitException) {
        assertTrue(true, 'rate limit ok');
    }
}

function testPermissionPlugin(): void
{
    $engine = makeEngine(new PluginManager([new PermissionPlugin(['admin'])]));
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('perm')
            ->metadata(['allowedRoles' => ['operator']])
            ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
    );

    try {
        $engine->start($compiled, ['role' => 'guest']);
        assertTrue(false, 'should deny guest');
    } catch (WorkflowPermissionException) {
        assertTrue(true, 'permission denied ok');
    }

    $runId = $engine->start($compiled, ['role' => 'operator']);
    assertTrue($runId !== '', 'operator should pass');
}

function testMcpFactoryListServers(): void
{
    WorkflowService::reset();
    $servers = WorkflowService::mcpFactory()->listServers('tenant_a');
    assertTrue(count($servers) >= 1, 'should list mcp servers');
}

function testMcpProcessRunnerLimit(): void
{
    McpProcessRunner::reset();
    $runner = new McpProcessRunner(maxLocalProcesses: 1);
    $runner->acquire('local');
    try {
        $runner->acquire('local');
        assertTrue(false, 'should hit process limit');
    } catch (McpProcessLimitException) {
        assertTrue(true, 'process limit ok');
    } finally {
        $runner->release();
        $runner->release();
    }
}

$tests = [
    'vector store factory' => 'testVectorStoreFactoryFileMode',
    'rag ingest + retrieve' => 'testRagIngestAndRetrieve',
    'retrieval tool factory' => 'testRetrievalToolFactory',
    'order saga compensation' => 'testOrderSagaCompensation',
    'cost aware router' => 'testCostAwareRouter',
    'rate limit release' => 'testRateLimitPluginRelease',
    'rate limit exceeded' => 'testRateLimitExceeded',
    'permission plugin' => 'testPermissionPlugin',
    'mcp list servers' => 'testMcpFactoryListServers',
    'mcp process runner' => 'testMcpProcessRunnerLimit',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Phase 4 workflow tests passed.\n";
