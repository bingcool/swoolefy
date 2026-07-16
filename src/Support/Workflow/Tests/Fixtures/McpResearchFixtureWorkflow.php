<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use Swoolefy\Support\AI\Builder\AINodeBuilder;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Tests\Fixtures\ResearchSummaryDto;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;

/** Support 单测用 mcp_research（stub executor，无真实 MCP）。 */
final class McpResearchFixtureWorkflow
{
    /**
     * @param array<string, mixed>|null $mockSummary
     */
    public static function definition(
        NeuronFactory $neuronFactory,
        ?array $mockSummary = null,
    ): WorkflowDefinition {
        $research = AINodeBuilder::make('research')
            ->promptKey('query')
            ->executor(static function ($ctx, $state): array {
                unset($ctx);

                return [
                    'content' => 'Research completed for: ' . (string) $state->get('query', ''),
                    'mcpToolsUsed' => [],
                ];
            })
            ->build(neuronFactory: $neuronFactory);

        $summarize = AINodeBuilder::make('summarize')
            ->structured(ResearchSummaryDto::class, outputKey: 'summary')
            ->executor(static function ($ctx, $state) use ($mockSummary): ResearchSummaryDto {
                unset($ctx);
                $dto = new ResearchSummaryDto();
                if (is_array($mockSummary) && $mockSummary !== []) {
                    $dto->summary = (string) ($mockSummary['summary'] ?? 'mock summary');
                    $dto->urgent = (bool) ($mockSummary['urgent'] ?? false);
                    $dto->source = (string) ($mockSummary['source'] ?? 'mcp_research_mock');

                    return $dto;
                }

                $query = (string) $state->get('query', '');
                $dto->summary = 'Analysis of ' . $query;
                $dto->urgent = str_contains(strtolower($query), 'urgent');
                $dto->source = 'mcp_research';

                return $dto;
            })
            ->build();

        return WorkflowDefinition::create('mcp_research', '1.0.0')
            ->metadata([
                'owner' => 'support-tests',
                'description' => 'Fixture MCP research then urgent notify / archive',
            ])
            ->registerSchema('summary', ResearchSummaryDto::class)
            ->addNode('research', $research)
            ->addNode('summarize', $summarize)
            ->addNode('notify', new ClosureNode('notify', static fn () => NodeExecutionResult::success([
                'notified' => true,
            ])))
            ->addNode('archive', new ClosureNode('archive', static fn () => NodeExecutionResult::success([
                'archived' => true,
            ])))
            ->addEdge('research', 'summarize')
            ->addConditionalEdges('summarize', [
                'notify' => EdgeCondition::when("data['summary']['urgent'] == true"),
                'archive' => EdgeCondition::when("data['summary']['urgent'] == false"),
            ], default: 'archive');
    }
}
