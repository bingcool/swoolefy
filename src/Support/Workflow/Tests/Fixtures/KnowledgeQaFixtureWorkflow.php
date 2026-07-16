<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Node\RagRetrieveNode;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/** Support 单测用 knowledge_qa：retrieve → extractive answer。 */
final class KnowledgeQaFixtureWorkflow
{
    public static function definition(
        RetrievalService $retrievalService,
        NeuronFactory $neuronFactory,
    ): WorkflowDefinition {
        unset($neuronFactory);

        return WorkflowDefinition::create('knowledge_qa', '1.0.0')
            ->addNode('retrieve', new RagRetrieveNode('retrieve', [
                'knowledgeBase' => 'product_kb',
                'queryKey' => 'question',
                'outputKey' => 'retrievedDocs',
                'topK' => 3,
            ], $retrievalService))
            ->addNode('answer', new ClosureNode('answer', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $docs = $state->get('retrievedDocs', []);
                $answer = 'No relevant answer found.';
                if (is_array($docs) && $docs !== []) {
                    $first = $docs[0]['content'] ?? '';
                    if (is_string($first) && $first !== '') {
                        $answer = $first;
                    }
                }

                return NodeExecutionResult::success(['answer' => $answer]);
            }))
            ->addEdge('retrieve', 'answer');
    }
}
