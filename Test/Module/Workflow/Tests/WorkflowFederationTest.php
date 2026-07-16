<?php

declare(strict_types=1);

/**
 * Workflow 联邦装配 + RunStore↔Registry 绑定回归。
 *
 * ```bash
 * php Test/Module/Workflow/Tests/WorkflowFederationTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Research\ResearchWorkflowService;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testFederatedCatalogDelegatesWithoutOwningRuntime(): void
{
    WorkflowService::reset();

    $ids = WorkflowService::registry()->ids();
    foreach ([
        'order_processing',
        'order_saga',
        'outdoor_cycling',
        'multi_agent_research',
        'mcp_research',
        'rag_qa',
        'contract_review',
        'knowledge_qa',
    ] as $id) {
        assertTrue(in_array($id, $ids, true), "catalog should list {$id}");
    }

    assertTrue(
        WorkflowService::registryFor('order_processing') === OrderWorkflowService::registry(),
        'order_processing owned by Order registry',
    );
    assertTrue(
        WorkflowService::registryFor('outdoor_cycling') === OutdoorWorkflowService::registry(),
        'outdoor_cycling owned by Outdoor registry',
    );
    assertTrue(
        WorkflowService::registryFor('multi_agent_research') === ResearchWorkflowService::registry(),
        'multi_agent_research owned by Research registry',
    );
    assertTrue(
        WorkflowService::registryFor('contract_review') === WorkflowService::registry(),
        'contract_review owned by hub registry',
    );
}

function testSameRegistryReusesRunStore(): void
{
    WorkflowComponentFactory::resetRunStores();
    $registry = new WorkflowRegistry();
    $a = WorkflowComponentFactory::runStore($registry);
    $b = WorkflowComponentFactory::runStore($registry);
    assertTrue($a === $b, 'same registry must reuse RunStore binding');

    $other = new WorkflowRegistry();
    $c = WorkflowComponentFactory::runStore($other);
    assertTrue($a !== $c, 'different registry must get distinct RunStore');
}

function testModuleStartVisibleViaEngineForRun(): void
{
    WorkflowService::reset();

    $definition = OrderProcessingWorkflow::definition(
        OrderWorkflowService::neuronFactory(),
        static function ($ctx, $state): OrderDecisionDto {
            unset($ctx, $state);
            $dto = new OrderDecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.95;
            $dto->reason = 'federation test';

            return $dto;
        },
    );
    $compiled = (new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator()))->compile($definition);
    $runId = OrderWorkflowService::engine()->start($compiled, [
        'orderId' => 'ORD-FED-1',
        'sessionId' => 's-fed-1',
    ]);

    // 统一 API 路径：按 runId 路由到 Order 绑定 Store
    $run = WorkflowService::engineForRun($runId)->getRun($runId);
    assertTrue($run->status === RunStatus::COMPLETED, 'engineForRun should hydrate module run');
    assertTrue($run->compiled->workflowId() === 'order_processing', 'workflowId preserved');

    // 错误 Registry 的内存 Store 不应看到该 run
    $hubStore = WorkflowComponentFactory::runStore(WorkflowService::registry());
    assertTrue($hubStore->find($runId) === null, 'hub RunStore must not hold Order module memory run');
}

function testEngineForUsesOwnerRegistryBinding(): void
{
    WorkflowService::reset();

    $orderEngine = WorkflowService::engineFor('order_processing');
    $orderEngine2 = OrderWorkflowService::engine();
    assertTrue(
        $orderEngine->runStore() === $orderEngine2->runStore(),
        'engineFor(Order) must share Order RunStore binding',
    );
}

$tests = [
    'federated catalog delegates' => 'testFederatedCatalogDelegatesWithoutOwningRuntime',
    'same registry reuses run store' => 'testSameRegistryReusesRunStore',
    'module start via engineForRun' => 'testModuleStartVisibleViaEngineForRun',
    'engineFor uses owner binding' => 'testEngineForUsesOwnerRegistryBinding',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Workflow federation tests passed.\n";
