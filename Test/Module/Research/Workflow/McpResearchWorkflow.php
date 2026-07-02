<?php

declare(strict_types=1);

namespace Test\Module\Research\Workflow;

use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Test\Module\Research\Agent\CodingResearchAgent;
use Test\Module\Research\Dto\ResearchSummaryDto;

/**
 * Phase 3 MCP 研究示例 —— research (MCP stub) → summarize → notify/archive。
 */
final class McpResearchWorkflow
{
    public static function definition(NeuronFactory $neuronFactory): WorkflowDefinition
    {
        return WorkflowDefinition::create('mcp_research', '1.0.0')
            ->registerSchema('summary', ResearchSummaryDto::class)
            ->addNode('research', AINodeBuilder::make('research')
                ->agent(CodingResearchAgent::class)
                ->promptKey('query')
                ->mcp(['github', 'brave_search'])
                ->executor(static function ($ctx, $state): array {
                    unset($ctx);

                    return [
                        'content' => 'Research completed for: ' . (string) $state->get('query', ''),
                        'mcpToolsUsed' => [],
                    ];
                })
                ->build(neuronFactory: $neuronFactory))
            ->addNode('summarize', AINodeBuilder::make('summarize')
                ->structured(ResearchSummaryDto::class, outputKey: 'summary')
                ->executor(static function ($ctx, $state): ResearchSummaryDto {
                    unset($ctx);
                    $dto = new ResearchSummaryDto();
                    $dto->summary = 'Analysis of ' . (string) $state->get('query', '');
                    $dto->urgent = str_contains(strtolower((string) $state->get('query', '')), 'urgent');
                    $dto->source = 'mcp_research';

                    return $dto;
                })
                ->build())
            ->addNode('notify', new ClosureNode('notify', static fn () => NodeExecutionResult::success(['notified' => true])))
            ->addNode('archive', new ClosureNode('archive', static fn () => NodeExecutionResult::success(['archived' => true])))
            ->addEdge('research', 'summarize')
            ->addConditionalEdges('summarize', [
                'notify' => EdgeCondition::when("data['summary']['urgent'] == true"),
                'archive' => EdgeCondition::when("data['summary']['urgent'] == false"),
            ], default: 'archive');
    }
}
