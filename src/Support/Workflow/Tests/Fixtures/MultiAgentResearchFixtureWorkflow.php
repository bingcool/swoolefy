<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;

/** Support 单测用 multi_agent_research（默认 mock Agent，无 LLM）。 */
final class MultiAgentResearchFixtureWorkflow
{
    public static function definition(AgentScheduler $scheduler): WorkflowDefinition
    {
        return WorkflowDefinition::create('multi_agent_research', '1.0.0')
            ->metadata([
                'owner' => 'support-tests',
                'description' => 'Fixture parallel coding + finance research',
            ])
            ->addAgentParallel('parallel_research', [
                'scheduler' => $scheduler,
                'router' => new StaticRouter(['coding', 'finance']),
                'agents' => [
                    'coding' => static function (RouterContext $ctx, NeuronFactory $factory): array {
                        unset($factory);
                        $query = (string) $ctx->state->get('query', 'Research topic');

                        return [
                            'topic' => 'coding',
                            'content' => 'Mock coding research for: ' . $query,
                        ];
                    },
                    'finance' => static function (RouterContext $ctx, NeuronFactory $factory): array {
                        unset($factory);
                        $query = (string) $ctx->state->get('query', 'Research topic');

                        return [
                            'topic' => 'finance',
                            'content' => 'Mock finance research for: ' . $query,
                        ];
                    },
                ],
            ])
            ->addNode('summary', new ClosureNode('summary', static function ($ctx, $state): NodeExecutionResult {
                unset($ctx);
                $outputs = $state->agentOutputs;
                $topics = array_map(
                    static fn ($item) => is_array($item) ? (string) ($item['topic'] ?? '') : '',
                    $outputs,
                );

                return NodeExecutionResult::success([
                    'summary' => [
                        'agentCount' => count($outputs),
                        'topics' => array_values(array_filter($topics)),
                    ],
                ]);
            }))
            ->addEdge('parallel_research', 'summary');
    }
}
