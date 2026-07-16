<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Support 单测用 order_processing DAG（不依赖 Test\Module\Order）。
 *
 * 保留与业务示例相同的条件边与 state 键，便于 Phase1/2/RunStore 回归。
 */
final class OrderProcessingFixtureWorkflow
{
    /**
     * @param callable|null $aiExecutor function (RunContext, WorkflowState): DecisionDto
     */
    public static function definition(
        NeuronFactory $neuronFactory,
        ?callable $aiExecutor = null,
        bool $pauseForHumanReview = false,
    ): WorkflowDefinition {
        $aiExecutor ??= static function () : DecisionDto {
            $dto = new DecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.9;
            $dto->reason = 'fixture default approve';

            return $dto;
        };

        $aiNode = AINode::make('ai_decision')
            ->structured(DecisionDto::class, outputKey: 'decision')
            ->promptKey('prompt')
            ->executor($aiExecutor)
            ->build(neuronFactory: $neuronFactory);

        $manualReview = $pauseForHumanReview
            ? new PauseNode('manual_review', [
                'assignee' => 'risk-team',
                'title' => 'Low confidence review',
            ])
            : new ClosureNode('manual_review', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);

                return NodeExecutionResult::success([
                    'manualReview' => true,
                    'orderStatus' => 'manual_review_passed',
                ]);
            });

        return WorkflowDefinition::create('order_processing', '1.1.0')
            ->metadata([
                'owner' => 'support-tests',
                'description' => 'Fixture order processing with AI decision routing',
            ])
            ->plugins(RetryPlugin::class, TracingPlugin::class)
            ->registerSchema('decision', DecisionDto::class)
            ->addNode('validate', new ClosureNode('validate', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $orderId = $state->get('orderId');
                if ($orderId === null || $orderId === '') {
                    return NodeExecutionResult::failed(new \InvalidArgumentException('orderId required'));
                }

                $order = [
                    'orderId' => $orderId,
                    'userId' => (string) ($state->get('userId') ?: 'u1'),
                    'amount' => (float) ($state->get('amount') ?? 0),
                    'currency' => (string) ($state->get('currency') ?: 'CNY'),
                    'status' => 'validated',
                ];

                return NodeExecutionResult::success([
                    'orderId' => $orderId,
                    'order' => $order,
                    'orderStatus' => 'validated',
                    'prompt' => 'Review order ' . $orderId,
                ]);
            }))
            ->addNode('ai_decision', $aiNode)
            ->addNode('payment', new ClosureNode('payment', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx);
                $payment = [
                    'paymentId' => 'pay_' . $state->get('orderId'),
                    'status' => 'captured',
                ];

                return NodeExecutionResult::success([
                    'payment' => $payment,
                    'paymentStatus' => 'captured',
                    'orderStatus' => 'paid',
                ]);
            }))
            ->addNode('manual_review', $manualReview)
            ->addNode('reject', new ClosureNode('reject', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx, $state);

                return NodeExecutionResult::success([
                    'rejectReason' => 'rejected by decision',
                    'orderStatus' => 'rejected',
                ]);
            }))
            ->addNode('complete', new ClosureNode('complete', static function (RunContext $ctx, WorkflowState $state): NodeExecutionResult {
                unset($ctx, $state);

                return NodeExecutionResult::success(['orderStatus' => 'completed']);
            }))
            ->addEdge('validate', 'ai_decision')
            ->addConditionalEdges('ai_decision', [
                'payment' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8",
                ),
                'manual_review' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] < 0.8",
                ),
                'reject' => EdgeCondition::when("data['decision']['approved'] == false"),
            ], default: 'reject')
            ->addEdge('manual_review', 'payment')
            ->addEdge('payment', 'complete');
    }
}
