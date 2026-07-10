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
 * Phase 1–4 补充集成测试：SubWorkflow、JsonLogic、RoundRobin、ComponentFactory。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowIntegrationTest.php
 */

use Swoolefy\Support\Agent\Router\RoundRobinRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\JsonLogicEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\SubWorkflowNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use NeuronAI\Providers\Anthropic\Anthropic;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Test\Module\Order\Agent\OrderDecisionAgent;

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

/** JsonLogic 条件边路由。 */
function testJsonLogicRouting(): void
{
    $evaluator = new JsonLogicEvaluator();
    $scheduler = new DagScheduler(new \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator());

    $definition = WorkflowDefinition::create('jsonlogic_demo')
        ->addNode('start', new ClosureNode('start', function ($ctx, WorkflowState $state) {
            $state->set('score', 85);

            return NodeExecutionResult::success(['score' => 85]);
        }))
        ->addNode('high', new ClosureNode('high', fn () => NodeExecutionResult::success(['tier' => 'high'])))
        ->addNode('low', new ClosureNode('low', fn () => NodeExecutionResult::success(['tier' => 'low'])))
        ->addConditionalEdges('start', [
            'high' => EdgeCondition::fromJsonLogic([
                '>=' => [
                    ['var' => 'data.score'],
                    80,
                ],
            ]),
            'low' => EdgeCondition::always(),
        ]);

    $compiler = new WorkflowCompiler(new \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator());
    $compiled = $compiler->compile($definition);
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: $scheduler,
    );
    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'JsonLogic run should complete');
    assertTrue(($run->state->get('tier') ?? '') === 'high', 'score>=80 should route to high');
    unset($evaluator);
    pass('jsonlogic routing');
}

/** SubWorkflowNode 嵌套执行。 */
function testSubWorkflowNode(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('child_flow', static fn () => WorkflowDefinition::create('child_flow')
        ->addNode('child_step', new ClosureNode('child_step', function ($ctx, WorkflowState $state) {
            $value = (int) ($state->get('value', 0));

            return NodeExecutionResult::success(['doubled' => $value * 2]);
        })));

    $engine = WorkflowComponentFactory::engine($registry);
    $runner = WorkflowComponentFactory::subWorkflowRunner($registry);
    $compiler = WorkflowComponentFactory::compiler();

    $parent = WorkflowDefinition::create('parent_flow')
        ->addNode('prepare', new ClosureNode('prepare', function ($ctx, WorkflowState $state) {
            $state->set('subWorkflowInput', ['value' => 21]);

            return NodeExecutionResult::success(['subWorkflowInput' => ['value' => 21]]);
        }))
        ->addNode('run_child', new SubWorkflowNode('run_child', [
            'workflowId' => 'child_flow',
            'inputKey' => 'subWorkflowInput',
            'outputKey' => 'subWorkflowOutput',
        ], $runner, $registry))
        ->addEdge('prepare', 'run_child');

    $compiled = $compiler->compile($parent);
    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'Parent run should complete');
    $output = $run->state->get('subWorkflowOutput');
    assertTrue(is_array($output) && ($output['doubled'] ?? null) === 42, 'Child should double value');
    pass('sub workflow node');
}

/** RoundRobinRouter 轮询。 */
function testRoundRobinRouter(): void
{
    $router = new RoundRobinRouter(['agent_a', 'agent_b', 'agent_c']);
    $ctx = new RouterContext('run-1', new WorkflowState(), ['agent_a', 'agent_b', 'agent_c']);

    $first = $router->route($ctx);
    $second = $router->route($ctx);
    $third = $router->route($ctx);
    $fourth = $router->route($ctx);

    assertTrue($first === ['agent_a'], 'First route should be agent_a');
    assertTrue($second === ['agent_b'], 'Second route should be agent_b');
    assertTrue($third === ['agent_c'], 'Third route should be agent_c');
    assertTrue($fourth === ['agent_a'], 'Fourth route should wrap to agent_a');
    pass('round robin router');
}

/** WorkflowComponentFactory 默认装配。 */
function testWorkflowComponentFactory(): void
{
    $registry = new WorkflowRegistry();
    $config = WorkflowConfig::fromArray([
        'workflow' => [
            'default_run_store' => WorkflowRunStoreName::MEMORY,
            'condition_evaluator' => 'symfony',
            'run_stores' => [
                WorkflowRunStoreName::MEMORY => [],
                WorkflowRunStoreName::REDIS => ['component' => WorkflowRunStoreName::REDIS],
                WorkflowRunStoreName::DB => ['component' => WorkflowRunStoreName::DB, 'table' => 'workflow_runs'],
            ],
        ],
    ]);
    $evaluator = WorkflowComponentFactory::conditionEvaluator($config);
    assertTrue($evaluator instanceof \Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface, 'Evaluator created');

    $store = WorkflowComponentFactory::runStore($registry, $config);
    assertTrue($store instanceof \Swoolefy\Support\Workflow\Engine\InMemoryRunStore, 'Default store is in-memory');

    $factoryDriver = ConditionEvaluatorFactory::create('symfony');
    assertTrue($factoryDriver instanceof \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator, 'Factory symfony driver');

    $explicit = ConditionEvaluatorFactory::create('composite');
    assertTrue($explicit instanceof \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator, 'explicit driver param honored');

    $fromConfig = ConditionEvaluatorFactory::create(
        WorkflowConfig::fromArray([
            'workflow' => ['condition_evaluator' => 'jsonlogic'],
        ])->conditionEvaluator(),
    );
    assertTrue($fromConfig instanceof \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator, 'workflow.php driver honored');
    pass('workflow component factory');
}

/** DbRunStore：SQLite 持久化 save/find/listWaiting。 */
function testDbRunStorePersistence(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('order_processing', static fn () => \Test\Module\Order\Workflow\OrderProcessingWorkflow::definition(
        new \Swoolefy\Support\Neuron\NeuronFactory(),
        static function (): \Test\Module\Order\Dto\OrderDecisionDto {
            $dto = new \Test\Module\Order\Dto\OrderDecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.95;
            $dto->reason = 'db store test';

            return $dto;
        },
    ));

    $pdo = new PDO('sqlite::memory:');
    WorkflowRunsSchemaInstaller::install($pdo);
    $store = new \Swoolefy\Support\Workflow\Engine\DbRunStore(
        $pdo,
        $registry,
        'workflow_runs',
    );

    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );

    $compiled = WorkflowComponentFactory::compiler(
        WorkflowConfig::fromArray([
            'workflow' => [
                'condition_evaluator' => 'symfony',
                'default_run_store' => WorkflowRunStoreName::MEMORY,
                'run_stores' => [WorkflowRunStoreName::MEMORY => []],
            ],
        ]),
    )->compile($registry->definition('order_processing'));

    $runId = $engine->start($compiled, ['orderId' => 'ORD-DB-1', 'amount' => 10]);
    $run = $engine->getRun($runId);
    assertTrue($run->status === RunStatus::COMPLETED, 'run completed');

    // 新 Engine + 同一 DbRunStore：模拟跨 Worker 读取
    $engine2 = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
        runStore: $store,
    );
    $restored = $engine2->getRun($runId);
    assertTrue($restored->runId === $runId, 'restored run id');
    assertTrue($restored->status === RunStatus::COMPLETED, 'restored status');
    assertTrue($restored->state->get('orderId') === 'ORD-DB-1', 'restored state');

    pass('db run store persistence');
}

/** RAG ingest CLI（无 embedding key 时走 mock/空向量路径）。 */
function testIngestCli(): void
{
    $script = dirname(__DIR__, 2) . '/Rag/Console/ingest_documents.php';
    $cmd = sprintf(
        'NEURON_ALLOW_FAKE_EMBEDDINGS=1 NEURON_TENANT_ID=integration php %s --kb=test_kb --text=%s 2>&1',
        escapeshellarg($script),
        escapeshellarg('integration test document'),
    );
    exec($cmd, $output, $code);
    $joined = implode("\n", $output);
    assertTrue($code === 0, "Ingest CLI failed: {$joined}");
    assertTrue(str_contains($joined, 'documentCount') || str_contains($joined, 'chunkCount'), 'Ingest should return counts');
    pass('rag ingest cli');
}

/** Neuron 默认 Provider 工厂与 Agent 覆盖检测。 */
function testNeuronProviderFactory(): void
{
    $factory = new NeuronProviderFactory();
    $provider = $factory->createFromParams(Anthropic::class, [
        'key' => 'sk-test-key',
        'model' => 'claude-3-5-sonnet-20241022',
    ]);
    assertTrue($provider instanceof Anthropic, 'Should instantiate Anthropic provider');

    assertTrue(
        NeuronProviderFactory::agentDeclaresCustomProvider(OrderDecisionAgent::class),
        'OrderDecisionAgent overrides provider()',
    );

    $config = \Swoolefy\Support\Neuron\NeuronAiConfig::fromArray([
        'neuron' => [
            'default_provider' => NeuronAiProviderName::ANTHROPIC,
            'ai_model_providers' => [
                NeuronAiProviderName::ANTHROPIC => [
                    'provider' => Anthropic::class,
                    'key' => 'sk-alias',
                    'model' => 'claude-test',
                ],
            ],
        ],
    ]);
    $aliasFactory = new NeuronProviderFactory($config);
    $fromAlias = $aliasFactory->createFromAlias(NeuronAiProviderName::ANTHROPIC);
    assertTrue($fromAlias instanceof Anthropic, 'Should create from ai_model_providers alias');

    pass('neuron provider factory');
}

$tests = [
    'jsonlogic routing' => 'testJsonLogicRouting',
    'sub workflow node' => 'testSubWorkflowNode',
    'round robin router' => 'testRoundRobinRouter',
    'workflow component factory' => 'testWorkflowComponentFactory',
    'db run store persistence' => 'testDbRunStorePersistence',
    'rag ingest cli' => 'testIngestCli',
    'neuron provider factory' => 'testNeuronProviderFactory',
];

foreach ($tests as $label => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] {$label}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo PHP_EOL . 'All ' . count($tests) . ' integration tests passed.' . PHP_EOL;
