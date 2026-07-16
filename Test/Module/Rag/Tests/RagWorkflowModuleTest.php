<?php

declare(strict_types=1);

/**
 * Rag 模块工作流独立性回归（与 Order/Outdoor 同一装配模式）。
 *
 * ```bash
 * php Test/Module/Rag/Tests/RagWorkflowModuleTest.php
 * ```
 */

use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Test\Module\Rag\RagService;
use Test\Module\Rag\RagWorkflowService;
use Test\Module\Rag\Workflow\RagQaWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bootRagService(): RagService
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

function testRagRegistryIndependent(): void
{
    RagWorkflowService::reset();
    WorkflowService::reset();

    $rag = RagWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($rag !== $central, 'Rag registry must be distinct');
    assertTrue($rag->has('rag_qa'), 'has rag_qa');
    assertTrue($rag->ids() === ['rag_qa'], 'only rag workflows');
    assertTrue(
        WorkflowService::registryFor('rag_qa') === $rag,
        'federation routes to Rag',
    );
}

function testRagQaViaModuleEngine(): void
{
    RagWorkflowService::reset();
    $service = bootRagService();
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

    assertTrue($run->status === RunStatus::COMPLETED, 'rag_qa should complete');
    assertTrue($run->compiled->workflowId() === 'rag_qa', 'workflowId');
}

$tests = [
    'rag registry independent' => 'testRagRegistryIndependent',
    'rag qa via module engine' => 'testRagQaViaModuleEngine',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Rag workflow module tests passed.\n";
