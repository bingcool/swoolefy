<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Contract;

use Swoolefy\Support\Workflow\Engine\RunStatus;
use PHPUintTest\TestCase;
use Test\Module\Contract\ContractWorkflowService;
use Test\Module\Workflow\WorkflowService;

/**
 * Contract 模块工作流独立性回归（contract_review HITL）。
 */
final class ContractWorkflowModuleTest extends TestCase
{
    /**
     * 验证：Contract 注册表独立，仅拥有 contract_review，且联邦路由正确。
     */
    public function testContractRegistryIsIndependent(): void
    {
        ContractWorkflowService::reset();
        WorkflowService::reset();

        $contract = ContractWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $contract, 'Contract registry must be a distinct instance');
        $this->assertTrue($contract->has('contract_review'), 'Contract registry should register contract_review');
        $this->assertSame(['contract_review'], $contract->ids(), 'Contract registry should only own contract workflows');
        $this->assertSame(
            $contract,
            WorkflowService::registryFor('contract_review'),
            'federation routes contract_review to Contract',
        );
    }

    /**
     * 验证：contract_review 经模块 Engine 在 legal_review 暂停，批准后完成并 published。
     */
    public function testContractReviewHitlApprove(): void
    {
        ContractWorkflowService::reset();

        $engine = ContractWorkflowService::engine();
        $runId = $engine->start(
            ContractWorkflowService::registry()->compiled('contract_review'),
            ['contractBrief' => 'Module test SaaS agreement'],
        );
        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::WAITING, $run->status, 'Should pause at legal_review');

        $engine->resume($runId, ['approved' => true, 'comment' => 'LGTM']);
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status, 'Should complete after approve');
        $this->assertTrue((bool) $run->state->get('published'), 'Should publish contract');
    }

    /**
     * 验证：拒绝→修订→再审→批准的 HITL 循环经模块 Engine 可完成。
     */
    public function testContractReviewHitlRejectThenApprove(): void
    {
        ContractWorkflowService::reset();

        $engine = ContractWorkflowService::engine();
        $runId = $engine->start(
            ContractWorkflowService::registry()->compiled('contract_review'),
            ['contractBrief' => 'Module test NDA'],
        );
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status);

        $engine->resume($runId, ['approved' => false, 'comment' => 'Need revision']);
        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::WAITING, $run->status, 'Should pause again after revise');
        $this->assertIsArray($run->state->get('contractDraft'), 'Draft should exist');

        $engine->resume($runId, ['approved' => true]);
        $this->assertSame(RunStatus::COMPLETED, $engine->getRun($runId)->status, 'Second approve completes');
    }
}
