<?php

declare(strict_types=1);

namespace Test\Module\Order\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 支付节点 —— 扣款成功后写入 payment 快照。
 *
 * Saga：compensate 将 paymentStatus captured → refunded（幂等）。
 */
final class PaymentNode extends AbstractNode
{
    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        unset($ctx);

        $orderId = $state->get('orderId');
        $amount = (float) ($state->get('amount') ?? 0);
        $currency = (string) ($state->get('currency') ?: 'CNY');
        $paymentId = 'pay_' . $orderId . '_' . substr(md5((string) microtime(true)), 0, 8);

        $payment = [
            'paymentId' => $paymentId,
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'captured',
        ];

        $state->set('paymentId', $paymentId);
        $state->set('paymentStatus', 'captured');
        $state->set('payment', $payment);
        $state->set('orderStatus', 'paid');
        $this->patchOrder($state, 'paid');

        return NodeExecutionResult::success([
            'paymentStatus' => 'captured',
            'payment' => $payment,
        ]);
    }

    /** {@inheritdoc} */
    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
        unset($ctx);
        if ($state->get('paymentStatus') !== 'captured') {
            return;
        }

        $state->set('paymentStatus', 'refunded');
        $payment = $state->get('payment');
        if (is_array($payment)) {
            $payment['status'] = 'refunded';
            $state->set('payment', $payment);
        }
        $state->set('orderStatus', 'refunded');
        $this->patchOrder($state, 'refunded');
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
