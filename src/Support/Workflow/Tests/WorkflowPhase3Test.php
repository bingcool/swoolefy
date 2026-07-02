<?php

declare(strict_types=1);

/**
 * Phase 3 工作流回归测试。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowPhase3Test.php
 */

use Swoolefy\Support\Agent\Router\LLMRouter;
use Swoolefy\Support\Agent\Router\WeightedRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
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

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

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

function testLLMRouterHeuristic(): void
{
    $router = new LLMRouter(['coding', 'finance']);
    $ctx = new RouterContext('r1', WorkflowState::fromInput(['query' => 'analyze api code']));
    $ids = $router->route($ctx);
    assertTrue(in_array('coding', $ids, true), 'LLMRouter should select coding for code query');
}

function testWeightedRouter(): void
{
    $router = new WeightedRouter(['coding' => 1.0, 'finance' => 0.0]);
    $ctx = new RouterContext('r2', WorkflowState::fromInput([]));
    $ids = $router->route($ctx);
    assertTrue($ids === ['coding'], 'WeightedRouter should always pick weight 1.0 agent');
}

function testPauseTaskListing(): void
{
    $engine = makeEngine();
    $compiled = WorkflowBootstrap::compiler()->compile(ContractReviewWorkflow::definition());
    $runId = $engine->start($compiled, ['contractBrief' => 'Test']);

    $tasks = $engine->listPauseTasks('legal-team');
    assertTrue(count($tasks) >= 1, 'Should list pause task for legal-team');
    assertTrue($tasks[0]['runId'] === $runId, 'Task runId should match');
}

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
