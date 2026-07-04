<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use InvalidArgumentException;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 订单校验节点 —— 规范化输入并写入订单快照。
 *
 * 必填：orderId
 * 可选：userId、sessionId、amount、currency、items
 */
final class ValidateNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);

        $orderId = $state->get('orderId');
        if ($orderId === null || $orderId === '') {
            return NodeExecutionResult::failed(new InvalidArgumentException('orderId is required'));
        }

        $amount = $state->get('amount');
        if ($amount !== null && (!is_numeric($amount) || (float) $amount < 0)) {
            return NodeExecutionResult::failed(new InvalidArgumentException('amount must be a non-negative number'));
        }

        $userId = (string) ($state->get('userId') ?: 'anonymous');
        $sessionId = (string) ($state->get('sessionId') ?: ('sess-' . $orderId));
        $amountValue = $amount !== null ? (float) $amount : 0.0;
        $currency = (string) ($state->get('currency') ?: 'CNY');
        $items = $state->get('items');
        $items = is_array($items) ? $items : [];

        $order = [
            'orderId' => $orderId,
            'userId' => $userId,
            'sessionId' => $sessionId,
            'amount' => $amountValue,
            'currency' => $currency,
            'items' => $items,
            'status' => 'validated',
        ];

        $state->set('orderId', $orderId);
        $state->set('userId', $userId);
        $state->set('sessionId', $sessionId);
        $state->set('amount', $amountValue);
        $state->set('currency', $currency);
        $state->set('items', $items);
        $state->set('order', $order);
        $state->set('orderStatus', 'validated');
        $state->set('validated', true);
        // AINode 默认 prompt 会读 orderId；补充可读上下文
        $state->set('prompt', sprintf(
            'Review order %s for user %s, amount %.2f %s, items=%s. Return risk decision.',
            (string) $orderId,
            $userId,
            $amountValue,
            $currency,
            json_encode($items, JSON_UNESCAPED_UNICODE),
        ));

        return NodeExecutionResult::success([
            'validated' => true,
            'order' => $order,
        ]);
    }
}
