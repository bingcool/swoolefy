<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/** Support 单测用 contract_review HITL DAG。 */
final class ContractReviewFixtureWorkflow
{
    public static function definition(): WorkflowDefinition
    {
        return WorkflowDefinition::create('contract_review', '1.0.0')
            ->metadata(['owner' => 'legal-team'])
            ->addNode('generate_contract', new ClosureNode('generate_contract', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $brief = (string) $state->get('contractBrief', 'Standard service agreement');
                $draft = [
                    'title' => 'Service Agreement',
                    'body' => "Draft contract based on: {$brief}",
                    'version' => 1,
                ];
                $state->set('contractDraft', $draft);

                return NodeExecutionResult::success(['contractDraft' => $draft]);
            }))
            ->addNode('legal_review', new PauseNode('legal_review', [
                'assignee' => 'legal-team',
                'title' => '合同法务确认',
                'payloadKeys' => ['contractDraft'],
            ]))
            ->addNode('revise_contract', new ClosureNode('revise_contract', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $draft = $state->get('contractDraft', []);
                if (is_array($draft)) {
                    $draft['version'] = ((int) ($draft['version'] ?? 1)) + 1;
                    $draft['body'] = ($draft['body'] ?? '') . ' [Revised per legal feedback]';
                    $state->set('contractDraft', $draft);
                }

                return NodeExecutionResult::success(['contractDraft' => $draft]);
            }))
            ->addNode('publish', new ClosureNode('publish', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $state->set('published', true);

                return NodeExecutionResult::success(['published' => true]);
            }))
            ->addEdge('generate_contract', 'legal_review')
            ->addConditionalEdges('legal_review', [
                'publish' => EdgeCondition::when("data['feedback']['approved'] == true"),
                'revise_contract' => EdgeCondition::when("data['feedback']['approved'] == false"),
            ], default: 'revise_contract')
            ->addEdge('revise_contract', 'legal_review');
    }
}
