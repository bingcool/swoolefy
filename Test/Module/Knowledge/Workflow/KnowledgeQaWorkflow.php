<?php

declare(strict_types=1);

namespace Test\Module\Knowledge\Workflow;

use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Node\RagRetrieveNode;
use Swoolefy\Support\Rag\Node\RAGNode;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Test\Module\Knowledge\Agent\ProductKnowledgeRag;

/**
 * Phase 3 知识库问答示例 —— retrieve → answer。
 */
final class KnowledgeQaWorkflow
{
    public static function definition(RetrievalService $retrievalService, NeuronFactory $neuronFactory): WorkflowDefinition
    {
        return WorkflowDefinition::create('knowledge_qa', '1.0.0')
            ->addNode('retrieve', new RagRetrieveNode('retrieve', [
                'knowledgeBase' => 'product_kb',
                'queryKey' => 'question',
                'outputKey' => 'retrievedDocs',
                'topK' => 3,
            ], $retrievalService))
            ->addNode('answer', RAGNode::make('answer')
                ->ragAgent(ProductKnowledgeRag::class)
                ->promptKey('question')
                ->memory()
                ->executor(static function ($ctx, $state) use ($neuronFactory): string {
                    unset($ctx);
                    $docs = $state->get('retrievedDocs', []);
                    if (is_array($docs) && $docs !== []) {
                        $first = $docs[0]['content'] ?? '';

                        return is_string($first) && $first !== ''
                            ? $first
                            : 'No relevant answer found.';
                    }

                    return 'No relevant answer found.';
                })
                ->build(neuronFactory: $neuronFactory))
            ->addEdge('retrieve', 'answer');
    }
}
