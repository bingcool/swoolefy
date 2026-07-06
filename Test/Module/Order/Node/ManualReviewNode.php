<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 人工复核节点 —— AI 低置信度批准时的中间环节。
 *
 * 默认自动通过（演示/单测）；构造参数 pauseForHuman=true 时返回 WAITING，需 resume。
 */
final class ManualReviewNode extends AbstractNode
{
    public function __construct(
        string $nodeId,
        private readonly bool $pauseForHuman = false,
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $decision = $state->get('decision');
        $reason = is_array($decision) ? (string) ($decision['reason'] ?? '') : '';

        if ($this->pauseForHuman) {
            $state->set('orderStatus', 'manual_review');
            $this->patchOrder($state, 'manual_review');

            return NodeExecutionResult::waiting([
                'assignee' => 'risk-ops',
                'title' => 'Order manual review',
                'runId' => $ctx->runId,
                'nodeId' => $this->nodeId,
                'payload' => [
                    'orderId' => $state->get('orderId'),
                    'decision' => $decision,
                ],
            ]);
        }

        $state->set('manualReview', true);
        $state->set('manualReviewReason', $reason !== '' ? $reason : 'auto-approved in demo');
        $state->set('orderStatus', 'manual_review_passed');
        $this->patchOrder($state, 'manual_review_passed');

        return NodeExecutionResult::success([
            'manualReview' => true,
            'manualReviewReason' => $state->get('manualReviewReason'),
        ]);
    }

    /** {@inheritdoc} resume 后标记人工已通过。 */
    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
        unset($ctx);
        $approved = (bool) ($feedback['approved'] ?? true);
        $state->set('feedback', $feedback);
        $state->set('manualReview', $approved);
        $state->set('manualReviewReason', (string) ($feedback['reason'] ?? 'human reviewed'));
        $state->set('orderStatus', $approved ? 'manual_review_passed' : 'rejected');
        $this->patchOrder($state, (string) $state->get('orderStatus'));
    }

    private function patchOrder(WorkflowState $state, string $status): void
    {
        $order = $state->get('order');
        if (!is_array($order)) {
            return;
        }
        $order['status'] = $status;
        $state->set('order', $order);
    }
}
