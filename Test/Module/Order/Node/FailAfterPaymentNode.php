<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 故意失败节点 —— Saga 演示触发补偿链。
 *
 * 模拟下游通知服务不可用；payment / reserve 已成功，需逆序 compensate。
 */
final class FailAfterPaymentNode extends AbstractNode
{
    /** {@inheritdoc} 固定返回 FAILED，不修改 state。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx, $state);

        return NodeExecutionResult::failed(new \RuntimeException('Downstream notify service unavailable'));
    }
}
