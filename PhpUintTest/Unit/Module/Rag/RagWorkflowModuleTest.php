<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Rag;

use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use PhpUintTest\TestCase;
use Test\Module\Rag\RagService;
use Test\Module\Rag\RagWorkflowService;
use Test\Module\Rag\Workflow\RagQaWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * Rag 模块工作流独立性回归（与 Order/Outdoor 同一装配模式）。
 */
final class RagWorkflowModuleTest extends TestCase
{
    /**
     * 验证：Rag 注册表独立且联邦 registryFor('rag_qa') 路由至 Rag 模块。
     */
    public function testRagRegistryIndependent(): void
    {
        RagWorkflowService::reset();
        WorkflowService::reset();

        $rag = RagWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $rag, 'Rag registry must be distinct');
        $this->assertTrue($rag->has('rag_qa'), 'has rag_qa');
        $this->assertSame(['rag_qa'], $rag->ids(), 'only rag workflows');
        $this->assertSame($rag, WorkflowService::registryFor('rag_qa'), 'federation routes to Rag');
    }

    /**
     * 验证：种子知识库后 rag_qa 工作流经模块 engine 完成问答。
     */
    public function testRagQaViaModuleEngine(): void
    {
        RagWorkflowService::reset();
        $service = $this->bootRagService();
        $service->seed(RagService::DEFAULT_KNOWLEDGE_BASE);

        $definition = RagQaWorkflow::definition(
            $service->retrievalService(),
            RagService::DEFAULT_KNOWLEDGE_BASE,
        );
        $compiled = (new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator()))->compile($definition);
        $engine = RagWorkflowService::engine();
        $runId = $engine->start($compiled, [
            'question' => 'What is RAG in swoolefy?',
            'query' => 'What is RAG in swoolefy?',
            'knowledgeBase' => RagService::DEFAULT_KNOWLEDGE_BASE,
        ]);
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status, 'rag_qa should complete');
        $this->assertSame('rag_qa', $run->compiled->workflowId(), 'workflowId');
    }

    private function bootRagService(): RagService
    {
        RagService::reset();
        $basePath = sys_get_temp_dir() . '/swoolefy_rag_mod_' . getmypid();
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'embedding_dimension' => 1536,
                'allow_fake_embeddings' => true,
                'require_tenant_isolation' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => $basePath],
                ],
            ],
        ]);

        return RagService::boot($config);
    }
}
