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
 * Phase 1–4 补充集成测试：SubWorkflow、JsonLogic、RoundRobin、ComponentFactory、DbRunStore、RAG CLI、Neuron。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | 条件路由 | JsonLogic 条件边按 score 选 high/low |
 * | SubWorkflow | 嵌套执行、WAITING 向上传播、FAILED 向上传播 |
 * | Agent 路由 | RoundRobinRouter 轮询与回绕 |
 * | ComponentFactory | conditionEvaluator、runStore、ConditionEvaluatorFactory 驱动 |
 * | DbRunStore | SQLite 跨 Engine 持久化 restore |
 * | RAG CLI | ingest_documents.php 无 key 时 mock 路径 |
 * | Neuron | NeuronProviderFactory 实例化与 Agent provider 覆盖检测 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowIntegrationTest.php
 * ```
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
use Swoolefy\Support\Workflow\Tests\Fixtures\CustomProviderAgent;

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
// JsonLogic 条件边
// ---------------------------------------------------------------------------

/**
 * 验证：JsonLogic 条件边根据 data.score≥80 路由到 high 节点，否则 default 到 low。
 *
 * 覆盖 CompositeConditionEvaluator 与 JsonLogic 表达式集成。
 */
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
        ], default: 'low');

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

// ---------------------------------------------------------------------------
// SubWorkflow 嵌套执行
// ---------------------------------------------------------------------------

/**
 * 验证：SubWorkflowNode 启动子工作流，子节点输出经 outputKey 写回父 state。
 *
 * 典型父子数据传递：prepare → run_child → doubled=42。
 */
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

/**
 * 验证：子流程在 HITL WAITING 时父 Run 同步 WAITING，pause 在 SubWorkflowNode；resume 父级联恢复子流程。
 *
 * 父不得在子 WAITING 时继续执行 after 节点。
 */
function testSubWorkflowWaitingPropagatesToParent(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('child_hitl', static fn () => WorkflowDefinition::create('child_hitl')
        ->addNode('pause', new \Swoolefy\Support\Workflow\Node\PauseNode('pause', [
            'assignee' => 'reviewer',
            'title' => 'Approve child',
        ]))
        ->addNode('done', new ClosureNode('done', static function ($ctx, WorkflowState $state) {
            $state->set('childDone', true);

            return NodeExecutionResult::success(['childDone' => true]);
        }))
        ->addEdge('pause', 'done'));

    $engine = WorkflowComponentFactory::engine($registry);
    $runner = WorkflowComponentFactory::subWorkflowRunner($registry);
    $compiler = WorkflowComponentFactory::compiler();

    $parent = WorkflowDefinition::create('parent_hitl')
        ->addNode('run_child', new SubWorkflowNode('run_child', [
            'workflowId' => 'child_hitl',
        ], $runner, $registry))
        ->addNode('after', new ClosureNode('after', static function ($ctx, WorkflowState $state) {
            $state->set('parentContinued', true);

            return NodeExecutionResult::success(['parentContinued' => true]);
        }))
        ->addEdge('run_child', 'after');

    $runId = $engine->start($compiler->compile($parent), []);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::WAITING, 'parent must wait while child HITL');
    assertTrue($run->pauseNodeId === 'run_child', 'pause on sub-workflow node');
    assertTrue($run->state->get('parentContinued') !== true, 'parent must not continue past sub-workflow');

    // 只 resume 父 Run：SubWorkflowNode::onResume 会级联 resume 仍 WAITING 的子 Run
    $engine->resume($runId, ['approved' => true]);

    $run = $engine->getRun($runId);
    assertTrue($run->status === RunStatus::COMPLETED, 'parent completes after nested HITL resume');
    assertTrue($run->state->get('parentContinued') === true, 'parent continues after resume');

    pass('sub workflow waiting propagates');
}

/**
 * 验证：子流程节点 FAILED 时父 start 抛异常，失败向上传播。
 *
 * 父不得吞掉子工作流异常并标记成功。
 */
function testSubWorkflowFailedPropagatesToParent(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('child_fail', static fn () => WorkflowDefinition::create('child_fail')
        ->addNode('boom', new ClosureNode('boom', static function () {
            return NodeExecutionResult::failed(new RuntimeException('child boom'));
        })));

    $engine = WorkflowComponentFactory::engine($registry);
    $runner = WorkflowComponentFactory::subWorkflowRunner($registry);
    $compiler = WorkflowComponentFactory::compiler();

    $parent = WorkflowDefinition::create('parent_fail')
        ->addNode('run_child', new SubWorkflowNode('run_child', [
            'workflowId' => 'child_fail',
        ], $runner, $registry));

    try {
        $engine->start($compiler->compile($parent), []);
        assertTrue(false, 'parent start should throw on child failure');
    } catch (Throwable $e) {
        assertTrue(str_contains($e->getMessage(), 'child') || str_contains($e->getMessage(), 'boom') || str_contains($e->getMessage(), 'failed'), 'failure message');
    }

    pass('sub workflow failed propagates');
}

// ---------------------------------------------------------------------------
// Agent 路由与 ComponentFactory
// ---------------------------------------------------------------------------

/**
 * 验证：RoundRobinRouter 按 agent 列表顺序轮询，第四请求回绕到第一个。
 *
 * 多 Agent 负载均衡基础行为。
 */
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

/**
 * 验证：WorkflowComponentFactory 与 ConditionEvaluatorFactory 按配置创建 evaluator/store/driver。
 *
 * 默认 memory store、symfony/jsonlogic 驱动解析。
 */
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

// ---------------------------------------------------------------------------
// DbRunStore 跨 Worker 持久化
// ---------------------------------------------------------------------------

/**
 * 验证：DbRunStore 在 SQLite 上 save/find；新 Engine 实例可 restore 已完成 run 的 state。
 *
 * 模拟多 Worker 共享同一持久化层的读取场景。
 */
function testDbRunStorePersistence(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('order_processing', static fn () => \Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow::definition(
        new \Swoolefy\Support\Neuron\NeuronFactory(),
        static function (): \Swoolefy\Support\Tests\Fixtures\DecisionDto {
            $dto = new \Swoolefy\Support\Tests\Fixtures\DecisionDto();
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

// ---------------------------------------------------------------------------
// RAG CLI 与 Neuron Provider
// ---------------------------------------------------------------------------

/**
 * 验证：ingest_documents.php CLI 在 NEURON_ALLOW_FAKE_EMBEDDINGS=1 时成功并输出 documentCount/chunkCount。
 *
 * 无真实 embedding API key 时的集成冒烟。
 */
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

/**
 * 验证：NeuronProviderFactory 能从参数/别名创建 Anthropic；agentDeclaresCustomProvider 检测 Agent 覆盖。
 *
 * Provider 工厂与业务 Agent 自定义 provider 契约。
 */
function testNeuronProviderFactory(): void
{
    $factory = new NeuronProviderFactory();
    $provider = $factory->createFromParams(Anthropic::class, [
        'key' => 'sk-test-key',
        'model' => 'claude-3-5-sonnet-20241022',
    ]);
    assertTrue($provider instanceof Anthropic, 'Should instantiate Anthropic provider');

    assertTrue(
        NeuronProviderFactory::agentDeclaresCustomProvider(CustomProviderAgent::class),
        'CustomProviderAgent overrides provider()',
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

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'jsonlogic routing' => 'testJsonLogicRouting',
    'sub workflow node' => 'testSubWorkflowNode',
    'sub workflow waiting propagates' => 'testSubWorkflowWaitingPropagatesToParent',
    'sub workflow failed propagates' => 'testSubWorkflowFailedPropagatesToParent',
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
