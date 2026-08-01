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

namespace PHPUintTest\Unit\Support\Workflow;

use Swoolefy\Support\Agent\Router\CostAwareRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Mcp\McpProcessLimitException;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
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
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderSagaFixtureWorkflow;
use Swoolefy\Support\Workflow\Tests\Fixtures\ProductKbSeeder;
use Swoolefy\Support\Workflow\Tests\Fixtures\WorkflowTestServices;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use PHPUintTest\TestCase;

/**
 * Phase 4 工作流回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | RAG | VectorStoreFactory 文件模式、RagIngestNode 入库与检索、RetrievalToolFactory |
 * | Saga | OrderSagaFixtureWorkflow 失败补偿与 COMPENSATED 状态 |
 * | 路由 | CostAwareRouter 预算内选低价 agent |
 * | 插件 | RateLimit 占位/释放、Permission 角色校验、限流+权限组合 |
 * | MCP | McpFactory 列表、McpProcessRunner 本地进程上限 |
 */
final class WorkflowPhase4Test extends TestCase
{
    /**
     * 构造 Phase 4 测试引擎，可注入自定义 PluginManager 与 InMemoryRunStore。
     *
     * 默认仅 RetryPlugin；Saga/限流等用例按需覆盖 plugins/store。
     */
    private function makeEngine(?PluginManager $plugins = null, ?InMemoryRunStore $store = null): WorkflowEngine
    {
        return new WorkflowEngine(
            plugins: $plugins ?? new PluginManager([new RetryPlugin()]),
            scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
            runStore: $store ?? new InMemoryRunStore(),
            events: new StreamWorkflowEventDispatcher(),
        );
    }

    /**
     * 验证：VectorStoreFactory 在 FILE 模式下 storeType=file 且能创建向量存储实例。
     *
     * 使用临时目录与 allow_fake_embeddings，无需真实 embedding API。
     */
    public function testVectorStoreFactoryFileMode(): void
    {
        $basePath = sys_get_temp_dir() . '/swoolefy_rag_test';
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => true,
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $basePath],
                ],
            ],
        ]);
        $factory = new VectorStoreFactory($config, $basePath);
        $this->assertTrue($factory->storeType() === 'file', 'store type file');
        $this->assertTrue($factory->make('test_kb') !== null, 'should create vector store');
    }

    /**
     * 验证：RagIngestNode 工作流入库 2 条文档后，RetrievalService 能检索到内容。
     *
     * 端到端覆盖 ingest 节点 + 检索服务集成。
     */
    public function testRagIngestAndRetrieve(): void
    {
        $rag = WorkflowTestServices::makeRagFactory();
        $pipeline = WorkflowTestServices::makeIngestionPipeline($rag);
        $engine = $this->makeEngine();
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
        $this->assertTrue($run->status === RunStatus::COMPLETED, 'ingest workflow should complete');
        $this->assertTrue(($run->state->get('ingestedCount') ?? 0) === 2, 'should ingest 2 docs');

        $docs = WorkflowTestServices::makeRetrievalService($rag)->retrieve('ingest_kb', 'Phase 4 ingest', 3);
        $this->assertTrue(count($docs) >= 1, 'retrieval should find ingested content');
    }

    /**
     * 验证：RetrievalToolFactory 能为 product_kb 创建名为 context_retrieval 的 Neuron 工具。
     *
     * Agent 侧 RAG 工具装配回归。
     */
    public function testRetrievalToolFactory(): void
    {
        $rag = WorkflowTestServices::makeRagFactory();
        (new ProductKbSeeder($rag))->seedProductKb();
        $tool = WorkflowTestServices::makeRetrievalToolFactory($rag)->make('product_kb');
        $this->assertTrue($tool->getName() === 'context_retrieval', 'should create Neuron RetrievalTool');
    }

    /**
     * 验证：OrderSagaFixtureWorkflow 在 notify 失败时抛异常，run 状态为 COMPENSATED 且支付/库存已回滚。
     *
     * 分布式 Saga 补偿路径的核心回归。
     */
    public function testOrderSagaCompensation(): void
    {
        $store = new InMemoryRunStore();
        $engine = $this->makeEngine(store: $store);
        $compiled = WorkflowBootstrap::compiler()->compile(OrderSagaFixtureWorkflow::definition());

        try {
            $engine->start($compiled, ['orderId' => 'ord-saga-1']);
            $this->assertTrue(false, 'saga workflow should throw on notify_fail');
        } catch (WorkflowException) {
            // expected
        }

        $runs = $store->all();
        $this->assertTrue(count($runs) >= 1, 'should have saved run');
        $run = $runs[count($runs) - 1];
        $this->assertTrue($run->status === RunStatus::COMPENSATED, 'run should be COMPENSATED after saga');
        $this->assertTrue($run->state->get('paymentStatus') === 'refunded', 'payment should be refunded');
        $this->assertTrue($run->state->get('inventoryReserved') === false, 'inventory should be released');
        $this->assertTrue(in_array('payment', $run->state->get('compensatedNodes') ?? [], true), 'payment should be compensated');
    }

    /**
     * 验证：CostAwareRouter 在预算内优先选择单价更低的 agent（cheap）。
     *
     * 长 query 仍须在 budgetUsd 约束下选路。
     */
    public function testCostAwareRouter(): void
    {
        $router = new CostAwareRouter(['cheap' => 0.001, 'expensive' => 0.05], budgetUsd: 0.01);
        $ctx = new RouterContext('r1', WorkflowState::fromInput(['query' => str_repeat('x', 100)]));
        $ids = $router->route($ctx);
        $this->assertTrue($ids === ['cheap'], 'should pick cheaper agent within budget');
    }

    /**
     * 验证：RateLimitPlugin 在 run 正常完成后 activeRuns 归零（槽位已释放）。
     *
     * 防止并发计数泄漏导致后续 start 被误拒。
     */
    public function testRateLimitPluginRelease(): void
    {
        $plugin = RateLimitPlugin::make(maxConcurrent: 2);
        $engine = $this->makeEngine(new PluginManager([$plugin]));
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('rl')
                ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
        );

        $engine->start($compiled, []);
        $this->assertTrue($plugin->activeRuns() === 0, 'slot released after complete');
    }

    /**
     * 验证：maxConcurrent=0 时 start 抛出 WorkflowRateLimitException。
     *
     * 零并发配置应拒绝一切新 run。
     */
    public function testRateLimitExceeded(): void
    {
        $plugin = RateLimitPlugin::make(maxConcurrent: 0);
        $engine = $this->makeEngine(new PluginManager([$plugin]));
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('rl0')
                ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
        );

        try {
            $engine->start($compiled, []);
            $this->assertTrue(false, 'should rate limit');
        } catch (WorkflowRateLimitException) {
            $this->assertTrue(true, 'rate limit ok');
        }
    }

    /**
     * 验证：PermissionPlugin 拒绝 guest 角色，允许 metadata.allowedRoles 内的 operator。
     *
     * 工作流级 RBAC 门禁。
     */
    public function testPermissionPlugin(): void
    {
        $engine = $this->makeEngine(new PluginManager([new PermissionPlugin(['admin'])]));
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('perm')
                ->metadata(['allowedRoles' => ['operator']])
                ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
        );

        try {
            $engine->start($compiled, ['role' => 'guest']);
            $this->assertTrue(false, 'should deny guest');
        } catch (WorkflowPermissionException) {
            $this->assertTrue(true, 'permission denied ok');
        }

        $runId = $engine->start($compiled, ['role' => 'operator']);
        $this->assertTrue($runId !== '', 'operator should pass');
    }

    /**
     * 验证：先 acquire 限流槽再被 Permission 拒绝时，槽位仍须释放；后续 admin 可正常 start。
     *
     * 避免「权限拒绝但占着限流槽」导致死锁式拒绝。
     */
    public function testRateLimitReleasedWhenPermissionDeniedAfterAcquire(): void
    {
        $rateLimit = RateLimitPlugin::make(maxConcurrent: 1);
        $engine = $this->makeEngine(new PluginManager([
            $rateLimit,
            new PermissionPlugin(['admin']),
        ]));
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('rl_perm')
                ->addNode('a', new ClosureNode('a', static fn ($c, $s) => NodeExecutionResult::success())),
        );

        try {
            $engine->start($compiled, ['role' => 'guest']);
            $this->assertTrue(false, 'should deny guest after rate-limit acquire');
        } catch (WorkflowPermissionException) {
            $this->assertTrue(true, 'permission denied');
        }

        $this->assertTrue($rateLimit->activeRuns() === 0, 'rate limit slot released after permission deny');

        $runId = $engine->start($compiled, ['role' => 'admin']);
        $this->assertTrue($runId !== '', 'admin should pass after slot release');
        $this->assertTrue($rateLimit->activeRuns() === 0, 'slot released after successful run');
    }

    /**
     * 验证：WorkflowTestServices::makeMcpFactory()->listServers() 至少返回一个已配置 server。
     *
     * MCP 集成配置加载回归。
     */
    public function testMcpFactoryListServers(): void
    {
        $servers = WorkflowTestServices::makeMcpFactory()->listServers();
        $this->assertTrue(count($servers) >= 1, 'should list mcp servers');
    }

    /**
     * 验证：McpProcessRunner maxLocalProcesses=1 时第二次 acquire 抛 McpProcessLimitException。
     *
     * 本地 MCP 子进程数须受控，防止 fork 风暴。
     */
    public function testMcpProcessRunnerLimit(): void
    {
        McpProcessRunner::reset();
        $runner = new McpProcessRunner(maxLocalProcesses: 1);
        $runner->acquire('local');
        try {
            $runner->acquire('local');
            $this->assertTrue(false, 'should hit process limit');
        } catch (McpProcessLimitException) {
            $this->assertTrue(true, 'process limit ok');
        } finally {
            $runner->release();
            $runner->release();
        }
    }
}
