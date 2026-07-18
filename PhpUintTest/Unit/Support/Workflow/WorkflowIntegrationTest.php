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

namespace PhpUintTest\Unit\Support\Workflow;

use NeuronAI\Providers\Anthropic\Anthropic;
use PDO;
use RuntimeException;
use Swoolefy\Support\Agent\Router\RoundRobinRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Condition\JsonLogicEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Node\SubWorkflowNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\Tests\Fixtures\CustomProviderAgent;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use PhpUintTest\TestCase;
use Throwable;

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
 */
final class WorkflowIntegrationTest extends TestCase
{
    /**
     * 验证：JsonLogic 条件边根据 data.score≥80 路由到 high 节点，否则 default 到 low。
     *
     * 覆盖 CompositeConditionEvaluator 与 JsonLogic 表达式集成。
     */
    public function testJsonLogicRouting(): void
    {
        $evaluator = new JsonLogicEvaluator();
        $scheduler = new DagScheduler(new CompositeConditionEvaluator());

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

        $compiler = new WorkflowCompiler(new CompositeConditionEvaluator());
        $compiled = $compiler->compile($definition);
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: $scheduler,
        );
        $runId = $engine->start($compiled, []);
        $run = $engine->getRun($runId);

        $this->assertTrue($run->status === RunStatus::COMPLETED, 'JsonLogic run should complete');
        $this->assertTrue(($run->state->get('tier') ?? '') === 'high', 'score>=80 should route to high');
        unset($evaluator);
    }

    /**
     * 验证：SubWorkflowNode 启动子工作流，子节点输出经 outputKey 写回父 state。
     *
     * 典型父子数据传递：prepare → run_child → doubled=42。
     */
    public function testSubWorkflowNode(): void
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

        $this->assertTrue($run->status === RunStatus::COMPLETED, 'Parent run should complete');
        $output = $run->state->get('subWorkflowOutput');
        $this->assertTrue(is_array($output) && ($output['doubled'] ?? null) === 42, 'Child should double value');
    }

    /**
     * 验证：子流程在 HITL WAITING 时父 Run 同步 WAITING，pause 在 SubWorkflowNode；resume 父级联恢复子流程。
     *
     * 父不得在子 WAITING 时继续执行 after 节点。
     */
    public function testSubWorkflowWaitingPropagatesToParent(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('child_hitl', static fn () => WorkflowDefinition::create('child_hitl')
            ->addNode('pause', new PauseNode('pause', [
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

        $this->assertTrue($run->status === RunStatus::WAITING, 'parent must wait while child HITL');
        $this->assertTrue($run->pauseNodeId === 'run_child', 'pause on sub-workflow node');
        $this->assertTrue($run->state->get('parentContinued') !== true, 'parent must not continue past sub-workflow');

        // 只 resume 父 Run：SubWorkflowNode::onResume 会级联 resume 仍 WAITING 的子 Run
        $engine->resume($runId, ['approved' => true]);

        $run = $engine->getRun($runId);
        $this->assertTrue($run->status === RunStatus::COMPLETED, 'parent completes after nested HITL resume');
        $this->assertTrue($run->state->get('parentContinued') === true, 'parent continues after resume');
    }

    /**
     * 验证：子流程节点 FAILED 时父 start 抛异常，失败向上传播。
     *
     * 父不得吞掉子工作流异常并标记成功。
     */
    public function testSubWorkflowFailedPropagatesToParent(): void
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
            $this->assertTrue(false, 'parent start should throw on child failure');
        } catch (Throwable $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'child') || str_contains($e->getMessage(), 'boom') || str_contains($e->getMessage(), 'failed'), 'failure message');
        }
    }

    /**
     * 验证：RoundRobinRouter 按 agent 列表顺序轮询，第四请求回绕到第一个。
     *
     * 多 Agent 负载均衡基础行为。
     */
    public function testRoundRobinRouter(): void
    {
        $router = new RoundRobinRouter(['agent_a', 'agent_b', 'agent_c']);
        $ctx = new RouterContext('run-1', new WorkflowState(), ['agent_a', 'agent_b', 'agent_c']);

        $first = $router->route($ctx);
        $second = $router->route($ctx);
        $third = $router->route($ctx);
        $fourth = $router->route($ctx);

        $this->assertTrue($first === ['agent_a'], 'First route should be agent_a');
        $this->assertTrue($second === ['agent_b'], 'Second route should be agent_b');
        $this->assertTrue($third === ['agent_c'], 'Third route should be agent_c');
        $this->assertTrue($fourth === ['agent_a'], 'Fourth route should wrap to agent_a');
    }

    /**
     * 验证：WorkflowComponentFactory 与 ConditionEvaluatorFactory 按配置创建 evaluator/store/driver。
     *
     * 默认 memory store、symfony/jsonlogic 驱动解析。
     */
    public function testWorkflowComponentFactory(): void
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
        $this->assertTrue($evaluator instanceof ConditionEvaluatorInterface, 'Evaluator created');

        $store = WorkflowComponentFactory::runStore($registry, $config);
        $this->assertTrue($store instanceof InMemoryRunStore, 'Default store is in-memory');

        $factoryDriver = ConditionEvaluatorFactory::create('symfony');
        $this->assertTrue($factoryDriver instanceof CompositeConditionEvaluator, 'Factory symfony driver');

        $explicit = ConditionEvaluatorFactory::create('composite');
        $this->assertTrue($explicit instanceof CompositeConditionEvaluator, 'explicit driver param honored');

        $fromConfig = ConditionEvaluatorFactory::create(
            WorkflowConfig::fromArray([
                'workflow' => ['condition_evaluator' => 'jsonlogic'],
            ])->conditionEvaluator(),
        );
        $this->assertTrue($fromConfig instanceof CompositeConditionEvaluator, 'workflow.php driver honored');
    }

    /**
     * 验证：DbRunStore 在 SQLite 上 save/find；新 Engine 实例可 restore 已完成 run 的 state。
     *
     * 模拟多 Worker 共享同一持久化层的读取场景。
     */
    public function testDbRunStorePersistence(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('order_processing', static fn () => OrderProcessingFixtureWorkflow::definition(
            new NeuronFactory(),
            static function (): DecisionDto {
                $dto = new DecisionDto();
                $dto->approved = true;
                $dto->confidence = 0.95;
                $dto->reason = 'db store test';

                return $dto;
            },
        ));

        $pdo = new PDO('sqlite::memory:');
        WorkflowRunsSchemaInstaller::install($pdo);
        $store = new DbRunStore(
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
        $this->assertTrue($run->status === RunStatus::COMPLETED, 'run completed');

        // 新 Engine + 同一 DbRunStore：模拟跨 Worker 读取
        $engine2 = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );
        $restored = $engine2->getRun($runId);
        $this->assertTrue($restored->runId === $runId, 'restored run id');
        $this->assertTrue($restored->status === RunStatus::COMPLETED, 'restored status');
        $this->assertTrue($restored->state->get('orderId') === 'ORD-DB-1', 'restored state');
    }

    /**
     * 验证：ingest_documents.php CLI 在 NEURON_ALLOW_FAKE_EMBEDDINGS=1 时成功并输出 documentCount/chunkCount。
     *
     * 无真实 embedding API key 时的集成冒烟。
     */
    public function testIngestCli(): void
    {
        $script = dirname(__DIR__, 4) . '/src/Support/Rag/Console/ingest_documents.php';
        $cmd = sprintf(
            'NEURON_ALLOW_FAKE_EMBEDDINGS=1 NEURON_TENANT_ID=integration php %s --kb=test_kb --text=%s 2>&1',
            escapeshellarg($script),
            escapeshellarg('integration test document'),
        );
        exec($cmd, $output, $code);
        $joined = implode("\n", $output);
        $this->assertTrue($code === 0, "Ingest CLI failed: {$joined}");
        $this->assertTrue(str_contains($joined, 'documentCount') || str_contains($joined, 'chunkCount'), 'Ingest should return counts');
    }

    /**
     * 验证：NeuronProviderFactory 能从参数/别名创建 Anthropic；agentDeclaresCustomProvider 检测 Agent 覆盖。
     *
     * Provider 工厂与业务 Agent 自定义 provider 契约。
     */
    public function testNeuronProviderFactory(): void
    {
        $factory = new NeuronProviderFactory();
        $provider = $factory->createFromParams(Anthropic::class, [
            'key' => 'sk-test-key',
            'model' => 'claude-3-5-sonnet-20241022',
        ]);
        $this->assertTrue($provider instanceof Anthropic, 'Should instantiate Anthropic provider');

        $this->assertTrue(
            NeuronProviderFactory::agentDeclaresCustomProvider(CustomProviderAgent::class),
            'CustomProviderAgent overrides provider()',
        );

        $config = NeuronAiConfig::fromArray([
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
        $this->assertTrue($fromAlias instanceof Anthropic, 'Should create from ai_model_providers alias');
    }
}
