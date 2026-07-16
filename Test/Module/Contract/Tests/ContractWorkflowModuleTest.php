<?php

declare(strict_types=1);

/**
 * Contract 模块工作流独立性回归（与 Order/Outdoor 同一装配模式）。
 *
 * ```bash
 * php Test/Module/Contract/Tests/ContractWorkflowModuleTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Engine\RunStatus;
use Test\Module\Contract\ContractWorkflowService;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testContractRegistryIndependent(): void
{
    ContractWorkflowService::reset();
    WorkflowService::reset();

    $contract = ContractWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($contract !== $central, 'Contract registry must be distinct');
    assertTrue($contract->has('contract_review'), 'has contract_review');
    assertTrue($contract->ids() === ['contract_review'], 'only contract workflows');
    assertTrue(
        WorkflowService::registryFor('contract_review') === $contract,
        'federation routes to Contract',
    );
}

function testContractHitlPauseViaModuleEngine(): void
{
    ContractWorkflowService::reset();

    $compiled = ContractWorkflowService::registry()->compiled('contract_review');
    $engine = ContractWorkflowService::engine();
    $runId = $engine->start($compiled, ['contractBrief' => 'SaaS agreement']);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::WAITING, 'should pause at legal_review');
    assertTrue($run->pauseNodeId === 'legal_review', 'pause node');

    $engine->resume($runId, ['approved' => true, 'comment' => 'LGTM']);
    $run = $engine->getRun($runId);
    assertTrue($run->status === RunStatus::COMPLETED, 'should complete after approve');
    assertTrue($run->state->get('published') === true, 'published');
}

function testEngineForRunRoutesToContract(): void
{
    ContractWorkflowService::reset();
    WorkflowService::reset();

    $runId = ContractWorkflowService::engine()->start(
        ContractWorkflowService::registry()->compiled('contract_review'),
        ['contractBrief' => 'route test'],
    );

    $run = WorkflowService::engineForRun($runId)->getRun($runId);
    assertTrue($run->status === RunStatus::WAITING, 'engineForRun finds contract run');
}

$tests = [
    'contract registry independent' => 'testContractRegistryIndependent',
    'contract HITL pause via module engine' => 'testContractHitlPauseViaModuleEngine',
    'engineForRun routes to contract' => 'testEngineForRunRoutesToContract',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Contract workflow module tests passed.\n";
