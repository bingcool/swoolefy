<?php

declare(strict_types=1);

namespace Test\Module\Research\Workflow;

use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Test\Module\Research\Agent\CodingResearchAgent;
use Test\Module\Research\Agent\FinanceResearchAgent;

/**
 * Phase 2 多 Agent 并行研究示例。
 *
 * parallel_research (AgentParallelNode) → summary
 */
final class MultiAgentResearchWorkflow
{
    public static function definition(AgentScheduler $scheduler): WorkflowDefinition
    {
        return WorkflowDefinition::create('multi_agent_research', '1.0.0')
            ->metadata(['owner' => 'research-team'])
            ->addAgentParallel('parallel_research', [
                'scheduler' => $scheduler,
                'router' => new StaticRouter(['coding', 'finance']),
                'agents' => [
                    'coding' => static function (RouterContext $ctx, NeuronFactory $factory): array {
                        $agent = $factory->create(CodingResearchAgent::class, $ctx->state, [
                            'promptKey' => 'query',
                        ]);
                        $content = $agent->chat(new UserMessage((string) $ctx->state->get('query', 'Research topic')))
                            ->getMessage()
                            ->getContent();

                        return ['topic' => 'coding', 'content' => $content];
                    },
                    'finance' => static function (RouterContext $ctx, NeuronFactory $factory): array {
                        $agent = $factory->create(FinanceResearchAgent::class, $ctx->state, [
                            'promptKey' => 'query',
                        ]);
                        $content = $agent->chat(new UserMessage((string) $ctx->state->get('query', 'Research topic')))
                            ->getMessage()
                            ->getContent();

                        return ['topic' => 'finance', 'content' => $content];
                    },
                ],
            ])
            ->addNode('summary', new ClosureNode('summary', static function ($ctx, $state): NodeExecutionResult {
                $outputs = $state->agentOutputs;
                $topics = array_map(static fn ($item) => is_array($item) ? ($item['topic'] ?? '') : '', $outputs);

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
