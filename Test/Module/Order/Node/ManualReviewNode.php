<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 人工复核节点 —— AI 低置信度批准时的中间环节。
 */
final class ManualReviewNode extends AbstractNode
{
    /** {@inheritdoc} 标记已进入人工复核。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $state->set('manualReview', true);

        return NodeExecutionResult::success(['manualReview' => true]);
    }
}
