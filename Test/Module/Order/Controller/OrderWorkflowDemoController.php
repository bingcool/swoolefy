<?php

declare(strict_types=1);

namespace Test\Module\Order\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * 订单工作流演示控制器 —— 展示 order_processing / order_saga 用法。
 *
 * POST /api/v1/order/workflow/process
 *   启动订单处理（AI 风控三分支）。Body:
 *   {
 *     "orderId": "ORD-10001",
 *     "userId": "u1",
 *     "sessionId": "s1",
 *     "amount": 199.00,
 *     "currency": "CNY",
 *     "items": [{"sku":"SKU-1","qty":1}],
 *     "mockDecision": {"approved":true,"confidence":0.95,"reason":"..."}  // 可选，注入 mock AI
 *   }
 *
 * POST /api/v1/order/workflow/saga
 *   启动 Saga 补偿演示（支付后通知失败 → 退款 + 释库存）。
 *
 * GET  /api/v1/order/workflow/status?runId=
 * POST /api/v1/order/workflow/resume  Body: { "runId", "feedback": {"approved":true,"reason":"..."} }
 */
final class OrderWorkflowDemoController extends BController
{
    /**
     * 演示：订单处理主流程。
     *
     * POST /api/v1/order/workflow/process
     */
    public function process(RequestInput $requestInput): array
    {
        $input = $this->normalizeOrderInput($requestInput);
        $mock = $requestInput->input('mockDecision');

        $definition = is_array($mock) && $mock !== []
            ? OrderProcessingWorkflow::definition($this->mockDecisionExecutor($mock))
            : OrderProcessingWorkflow::definition();

        return $this->startAndFormat($definition->id(), $input, $definition);
    }

    /**
     * 演示：Saga 补偿（支付成功后通知失败）。
     *
     * POST /api/v1/order/workflow/saga
     */
    public function saga(RequestInput $requestInput): array
    {
        $input = $this->normalizeOrderInput($requestInput);
        $definition = OrderSagaWorkflow::definition();

        return $this->startAndFormat($definition->id(), $input, $definition);
    }

    /**
     * 查询 Run 状态。
     *
     * GET /api/v1/order/workflow/status?runId=
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = WorkflowService::engine()->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }

        return $this->formatRun($run);
    }

    /**
     * HITL 恢复（manual_review pauseForHuman=true 时使用）。
     *
     * POST /api/v1/order/workflow/resume
     * Body: { "runId": "...", "feedback": { "approved": true, "reason": "ok" } }
     */
    public function resume(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        $feedback = $requestInput->input('feedback', []);
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }
        if (!is_array($feedback)) {
            throw new SystemException('feedback must be an object', 400);
        }

        try {
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $engine->resume($runId, $feedback);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return $this->formatRun($run);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function startAndFormat(
        string $workflowId,
        array $input,
        \Swoolefy\Support\Workflow\Definition\WorkflowDefinition $definition,
    ): array {
        try {
            // 演示可注入 mock definition；Engine 走 workflow.php RunStore（跨 Worker status/resume）
            $compiled = WorkflowComponentFactory::compiler()->compile($definition);
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return $this->formatRun($run, $workflowId);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOrderInput(RequestInput $requestInput): array
    {
        $orderId = $requestInput->input('orderId');
        if ($orderId === null || $orderId === '') {
            throw new SystemException('orderId is required', 400);
        }

        $input = [
            'orderId' => $orderId,
            'userId' => (string) ($requestInput->input('userId') ?: 'demo-user'),
            'sessionId' => (string) ($requestInput->input('sessionId') ?: ('sess-' . $orderId)),
            'amount' => (float) ($requestInput->input('amount') ?? 99.0),
            'currency' => (string) ($requestInput->input('currency') ?: 'CNY'),
        ];

        $items = $requestInput->input('items');
        if (is_array($items)) {
            $input['items'] = $items;
        } else {
            $input['items'] = [['sku' => 'DEMO-SKU', 'qty' => 1]];
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $mock
     */
    private function mockDecisionExecutor(array $mock): callable
    {
        return static function ($ctx, $state) use ($mock): OrderDecisionDto {
            unset($ctx, $state);
            $dto = new OrderDecisionDto();
            $dto->approved = (bool) ($mock['approved'] ?? true);
            $dto->confidence = (float) ($mock['confidence'] ?? 0.9);
            $dto->reason = (string) ($mock['reason'] ?? 'mock decision from demo controller');

            return $dto;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRun(WorkflowRun $run, ?string $workflowId = null): array
    {
        $decision = $run->state->get('decision');

        return [
            'runId' => $run->runId,
            'workflowId' => $workflowId ?? $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            'orderStatus' => $run->state->get('orderStatus'),
            'order' => $run->state->get('order'),
            'decision' => $decision,
            'payment' => $run->state->get('payment'),
            'paymentStatus' => $run->state->get('paymentStatus'),
            'inventoryReserved' => $run->state->get('inventoryReserved'),
            'manualReview' => $run->state->get('manualReview'),
            'rejectReason' => $run->state->get('rejectReason'),
            'compensatedNodes' => $run->state->get('compensatedNodes'),
            'error' => $run->error,
            'data' => $run->state->data,
        ];
    }
}
