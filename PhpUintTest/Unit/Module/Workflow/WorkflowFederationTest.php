<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Workflow;

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use PhpUintTest\TestCase;
use Test\Module\Contract\ContractWorkflowService;
use Test\Module\Knowledge\KnowledgeWorkflowService;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Rag\RagWorkflowService;
use Test\Module\Research\ResearchWorkflowService;
use Test\Module\Workflow\WorkflowService;

/**
 * Workflow 联邦装配 + RunStore↔Registry 绑定回归。
 */
final class WorkflowFederationTest extends TestCase
{
    /**
     * 验证：联邦目录列出各模块工作流，且 registryFor 委托至对应模块注册表。
     */
    public function testFederatedCatalogDelegatesWithoutOwningRuntime(): void
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
            $this->assertContains($id, $ids, "catalog should list {$id}");
        }

        $this->assertSame(
            OrderWorkflowService::registry(),
            WorkflowService::registryFor('order_processing'),
            'order_processing owned by Order registry',
        );
        $this->assertSame(
            OutdoorWorkflowService::registry(),
            WorkflowService::registryFor('outdoor_cycling'),
            'outdoor_cycling owned by Outdoor registry',
        );
        $this->assertSame(
            ResearchWorkflowService::registry(),
            WorkflowService::registryFor('multi_agent_research'),
            'multi_agent_research owned by Research registry',
        );
        $this->assertSame(
            RagWorkflowService::registry(),
            WorkflowService::registryFor('rag_qa'),
            'rag_qa owned by Rag registry',
        );
        $this->assertSame(
            ContractWorkflowService::registry(),
            WorkflowService::registryFor('contract_review'),
            'contract_review owned by Contract registry',
        );
        $this->assertSame(
            KnowledgeWorkflowService::registry(),
            WorkflowService::registryFor('knowledge_qa'),
            'knowledge_qa owned by Knowledge registry',
        );
    }

    /**
     * 验证：同一 WorkflowRegistry 复用 RunStore 绑定，不同注册表获得独立实例。
     */
    public function testSameRegistryReusesRunStore(): void
    {
        WorkflowComponentFactory::resetRunStores();
        $registry = new WorkflowRegistry();
        $a = WorkflowComponentFactory::runStore($registry);
        $b = WorkflowComponentFactory::runStore($registry);
        $this->assertSame($b, $a, 'same registry must reuse RunStore binding');

        $other = new WorkflowRegistry();
        $c = WorkflowComponentFactory::runStore($other);
        $this->assertNotSame($c, $a, 'different registry must get distinct RunStore');
    }

    /**
     * 验证：模块 engine 启动的 run 可通过 engineForRun 读取，且 hub RunStore 不持有该 run。
     */
    public function testModuleStartVisibleViaEngineForRun(): void
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

        $run = WorkflowService::engineForRun($runId)->getRun($runId);
        $this->assertSame(RunStatus::COMPLETED, $run->status, 'engineForRun should hydrate module run');
        $this->assertSame('order_processing', $run->compiled->workflowId(), 'workflowId preserved');

        $hubStore = WorkflowComponentFactory::runStore(WorkflowService::registry());
        $this->assertNull($hubStore->find($runId), 'hub RunStore must not hold Order module memory run');
    }

    /**
     * 验证：engineFor(workflowId) 与模块 engine 共享同一 RunStore 绑定。
     */
    public function testEngineForUsesOwnerRegistryBinding(): void
    {
        WorkflowService::reset();

        $orderEngine = WorkflowService::engineFor('order_processing');
        $orderEngine2 = OrderWorkflowService::engine();
        $this->assertSame(
            $orderEngine2->runStore(),
            $orderEngine->runStore(),
            'engineFor(Order) must share Order RunStore binding',
        );
    }
}
