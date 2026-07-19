<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Knowledge;

use Swoolefy\Support\Workflow\Engine\RunStatus;
use PhpUintTest\TestCase;
use Test\Module\Knowledge\KnowledgeWorkflowService;
use Test\Module\Knowledge\Support\KnowledgeSeeder;
use Test\Module\Workflow\WorkflowService;

/**
 * Knowledge 模块工作流独立性回归（与 Order/Outdoor 同一装配模式）。
 */
final class KnowledgeWorkflowModuleTest extends TestCase
{
    /**
     * 验证：Knowledge 注册表独立且联邦 registryFor('knowledge_qa') 路由至 Knowledge 模块。
     */
    public function testKnowledgeRegistryIndependent(): void
    {
        KnowledgeWorkflowService::reset();
        WorkflowService::reset();

        $knowledge = KnowledgeWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $knowledge, 'Knowledge registry must be distinct');
        $this->assertTrue($knowledge->has('knowledge_qa'), 'has knowledge_qa');
        $this->assertSame(['knowledge_qa'], $knowledge->ids(), 'only knowledge workflows');
        $this->assertSame($knowledge, WorkflowService::registryFor('knowledge_qa'), 'federation routes to Knowledge');
    }

    /**
     * 验证：种子产品知识库后 knowledge_qa 工作流完成检索并生成 answer。
     */
    public function testKnowledgeQaViaModuleEngine(): void
    {
        KnowledgeWorkflowService::reset();
        (new KnowledgeSeeder(KnowledgeWorkflowService::ragFactory()))->seedProductKb();

        $engine = KnowledgeWorkflowService::engine();
        $runId = $engine->start(
            KnowledgeWorkflowService::registry()->compiled('knowledge_qa'),
            [
                'question' => '门框尺寸是多少？',
                'sessionId' => 's-kb-mod-1',
            ],
        );
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status, 'knowledge_qa should complete');
        $this->assertIsArray($run->state->get('retrievedDocs'), 'retrievedDocs');
        $this->assertIsString($run->state->get('answer'), 'answer');
    }
}
