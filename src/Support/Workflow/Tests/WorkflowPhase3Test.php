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
 * Phase 3 工作流回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | HITL | ContractReviewWorkflow 批准路径与拒绝修订循环 |
 * | RAG | KnowledgeQaWorkflow 检索与回答 |
 * | MCP | McpResearchWorkflow 摘要与 notify/archive 路由 |
 * | Agent 路由 | LLMRouter 启发式、WeightedRouter 权重选路 |
 * | 暂停任务 | listPauseTasks 按 legal-team assignee 列出 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowPhase3Test.php
 * ```
 */

use Swoolefy\Support\Agent\Router\LLMRouter;
use Swoolefy\Support\Agent\Router\WeightedRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Plugin\Builtin\AuditPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\OpenTelemetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Contract\Workflow\ContractReviewWorkflow;
use Test\Module\Knowledge\Support\KnowledgeSeeder;
use Test\Module\Knowledge\Workflow\KnowledgeQaWorkflow;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 通用断言与引擎夹具
// ---------------------------------------------------------------------------

/** 断言条件为真，否则抛 RuntimeException 使单测失败。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 构造 Phase 3 标准测试引擎：Retry + OTel + Audit，内存 RunStore，流式事件分发。
 *
 * 各业务工作流用例共用，减少重复装配代码。
 */
function makeEngine(): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: new PluginManager([
            new RetryPlugin(),
            new OpenTelemetryPlugin(),
            new AuditPlugin(),
        ]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        runStore: new InMemoryRunStore(),
        events: new StreamWorkflowEventDispatcher(),
    );
}

// ---------------------------------------------------------------------------
// 合同审阅 HITL
// ---------------------------------------------------------------------------

/**
 * 验证：ContractReviewWorkflow 在 legal_review 暂停，批准后完成并 published=true。
 *
 * 覆盖典型 HITL 批准单路径。
 */
function testContractReviewHitlApprove(): void
{
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(ContractReviewWorkflow::definition());

    $runId = $engine->start($compiled, ['contractBrief' => 'SaaS agreement']);
    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'waiting', 'Should pause at legal_review');

    $engine->resume($runId, ['approved' => true, 'comment' => 'LGTM']);
    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'completed', 'Should complete after approve');
    assertTrue(($run->state->get('published') ?? false) === true, 'Should publish contract');
}

/**
 * 验证：首次拒绝进入修订循环再次 WAITING；二次批准后完成。
 *
 * 覆盖 HITL 拒绝→修订→再审路径。
 */
function testContractReviewHitlRejectLoop(): void
{
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(ContractReviewWorkflow::definition());

    $runId = $engine->start($compiled, ['contractBrief' => 'NDA']);
    assertTrue($engine->getRun($runId)->status->value === 'waiting', 'Should wait');

    $engine->resume($runId, ['approved' => false, 'comment' => 'Need revision']);
    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'waiting', 'Should pause again after revise');
    assertTrue(is_array($run->state->get('contractDraft')), 'Draft should exist');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($engine->getRun($runId)->status->value === 'completed', 'Second approve completes');
}

// ---------------------------------------------------------------------------
// 知识库问答与 MCP 研究
// ---------------------------------------------------------------------------

/**
 * 验证：KnowledgeQaWorkflow 在种子知识库后能完成检索并生成 answer。
 *
 * 依赖 KnowledgeSeeder 预置 product_kb 文档。
 */
function testKnowledgeQaWorkflow(): void
{
    WorkflowService::reset();
    (new KnowledgeSeeder(WorkflowService::ragFactory()))->seedProductKb();

    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(
        KnowledgeQaWorkflow::definition(WorkflowService::retrievalService(), WorkflowService::neuronFactory()),
    );

    $runId = $engine->start($compiled, [
        'question' => '门框尺寸是多少？',
        'sessionId' => 's-kb-1',
    ]);
    $run = $engine->getRun($runId);

    assertTrue($run->status->value === 'completed', 'Knowledge QA should complete');
    assertTrue(is_array($run->state->get('retrievedDocs')), 'Should have retrieved docs');
    assertTrue(is_string($run->state->get('answer')), 'Should have answer');
}

/**
 * 验证：McpResearchWorkflow 完成并产出 summary，路由至 notify 或 archive。
 *
 * 覆盖 MCP 工具集成与条件边归档路径。
 */
function testMcpResearchWorkflow(): void
{
    WorkflowService::reset();
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(
        McpResearchWorkflow::definition(WorkflowService::neuronFactory()),
    );

    $runId = $engine->start($compiled, ['query' => 'urgent: analyze github issues']);
    $run = $engine->getRun($runId);

    assertTrue($run->status->value === 'completed', 'MCP research should complete');
    assertTrue(isset($run->state->data['summary']), 'Should have summary');
    assertTrue(($run->state->nodeOutputs['notify'] ?? null) !== null || $run->lastRoutedEdge === 'archive', 'Should route notify or archive');
}

// ---------------------------------------------------------------------------
// Agent 路由策略
// ---------------------------------------------------------------------------

/**
 * 验证：LLMRouter 对含 code/api 关键词的 query 启发式选择 coding agent。
 *
 * 无真实 LLM 调用时的规则回退路径。
 */
function testLLMRouterHeuristic(): void
{
    $router = new LLMRouter(['coding', 'finance']);
    $ctx = new RouterContext('r1', WorkflowState::fromInput(['query' => 'analyze api code']));
    $ids = $router->route($ctx);
    assertTrue(in_array('coding', $ids, true), 'LLMRouter should select coding for code query');
}

/**
 * 验证：WeightedRouter 在 finance 权重为 0 时始终选择 coding。
 *
 * 权重为 0 的 agent 不应被选中。
 */
function testWeightedRouter(): void
{
    $router = new WeightedRouter(['coding' => 1.0, 'finance' => 0.0]);
    $ctx = new RouterContext('r2', WorkflowState::fromInput([]));
    $ids = $router->route($ctx);
    assertTrue($ids === ['coding'], 'WeightedRouter should always pick weight 1.0 agent');
}

// ---------------------------------------------------------------------------
// 暂停任务列表
// ---------------------------------------------------------------------------

/**
 * 验证：ContractReviewWorkflow 启动后 listPauseTasks('legal-team') 包含当前 runId。
 *
 * 操作员待办列表 API 的基础行为。
 */
function testPauseTaskListing(): void
{
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(ContractReviewWorkflow::definition());
    $runId = $engine->start($compiled, ['contractBrief' => 'Test']);

    $tasks = $engine->listPauseTasks('legal-team');
    assertTrue(count($tasks) >= 1, 'Should list pause task for legal-team');
    assertTrue($tasks[0]['runId'] === $runId, 'Task runId should match');
}

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'HITL approve path' => 'testContractReviewHitlApprove',
    'HITL reject loop' => 'testContractReviewHitlRejectLoop',
    'knowledge QA' => 'testKnowledgeQaWorkflow',
    'MCP research' => 'testMcpResearchWorkflow',
    'LLM router' => 'testLLMRouterHeuristic',
    'weighted router' => 'testWeightedRouter',
    'pause task list' => 'testPauseTaskListing',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Phase 3 workflow tests passed.\n";
