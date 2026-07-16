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
use Test\Module\Order\OrderWorkflowService;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;

/**
 * 订单工作流演示控制器 —— 展示 order_processing / order_saga 用法。
 *
 * 本模块使用 {@see OrderWorkflowService} 独立 Registry / Engine，
 * 不依赖 Test\Module\Workflow\WorkflowService。
 *
 * 路由定义：`Test/Router/Module/OrderWorkflow.php`（前缀 `api`，默认端口 9501）。
 * 各方法 PHPDoc 中 curl 代码块无行首 `*`，便于直接复制执行。
 */
final class OrderWorkflowDemoController extends BController
{
    /**
     * 演示：订单处理主流程（AI 风控三分支）。
     *
     * Route: POST /api/v1/order/workflow/process
     *
     ```bash
     # 高置信度批准 → payment → complete
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "orderId": "ORD-10001",
         "userId": "u1",
         "sessionId": "s1",
         "amount": 199.00,
         "currency": "CNY",
         "items": [{"sku": "SKU-1", "qty": 1}],
         "mockDecision": {
           "approved": true,
           "confidence": 0.95,
           "reason": "high confidence approve"
         }
       }'

     # 低置信度 → manual_review → payment（自动通过复核）
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
       -H 'Content-Type: application/json' \
       -d '{
         "orderId": "ORD-10002",
         "amount": 88.00,
         "mockDecision": {
           "approved": true,
           "confidence": 0.55,
           "reason": "low confidence"
         }
       }'

     # 拒绝 → reject
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
       -H 'Content-Type: application/json' \
       -d '{
         "orderId": "ORD-10003",
         "mockDecision": {
           "approved": false,
           "confidence": 0.99,
           "reason": "policy reject"
         }
       }'

     # HITL：低置信度在 manual_review 暂停（WAITING），需再调 resume
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/process' \
       -H 'Content-Type: application/json' \
       -d '{
         "orderId": "ORD-10004",
         "pauseForHumanReview": true,
         "mockDecision": {
           "approved": true,
           "confidence": 0.55,
           "reason": "need human review"
         }
       }'
     ```
     */
    public function process(RequestInput $requestInput): array
    {
        $input = $this->normalizeOrderInput($requestInput);
        $mock = $requestInput->input('mockDecision');
        $pauseForHumanReview = filter_var(
            $requestInput->input('pauseForHumanReview', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        $aiExecutor = is_array($mock) && $mock !== []
            ? $this->mockDecisionExecutor($mock)
            : null;

        $definition = OrderProcessingWorkflow::definition(
            OrderWorkflowService::neuronFactory(),
            $aiExecutor,
            pauseForHumanReview: $pauseForHumanReview,
        );

        return $this->startAndFormat($input, $definition);
    }

    /**
     * 演示：Saga 补偿（支付成功后通知失败 → 退款 + 释库存）。
     *
     * Route: POST /api/v1/order/workflow/saga
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/saga' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "orderId": "ORD-SAGA-1",
         "userId": "u1",
         "amount": 50.00,
         "currency": "CNY",
         "items": [{"sku": "DEMO-SKU", "qty": 1}]
       }'
     ```
     */
    public function saga(RequestInput $requestInput): array
    {
        $input = $this->normalizeOrderInput($requestInput);
        $definition = OrderSagaWorkflow::definition();

        return $this->startAndFormat($input, $definition);
    }

    /**
     * 查询 Run 状态。
     *
     * Route: GET /api/v1/order/workflow/status
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/order/workflow/status?runId=run_xxxx' \
       -H 'Accept: application/json'
     ```
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = OrderWorkflowService::engine()->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }

        return $this->formatRun($run);
    }

    /**
     * HITL 恢复（manual_review pauseForHuman=true 时使用）。
     *
     * Route: POST /api/v1/order/workflow/resume
     *
     * 演示专用路径，不经 WorkflowController HITL 鉴权；生产请走 /api/v1/workflow/run/resume。
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/order/workflow/resume' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "runId": "run_xxxx",
         "feedback": {
           "approved": true,
           "reason": "ok"
         }
       }'
     ```
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
            $engine = OrderWorkflowService::engine(events: new StreamWorkflowEventDispatcher());
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
        array $input,
        \Swoolefy\Support\Workflow\Definition\WorkflowDefinition $definition,
    ): array {
        try {
            // 演示可注入 mock definition；Engine 走本模块 Registry + workflow.php RunStore
            $compiled = WorkflowComponentFactory::compiler()->compile($definition);
            $engine = OrderWorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return $this->formatRun($run);
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
    private function formatRun(WorkflowRun $run): array
    {
        $decision = $run->state->get('decision');

        return [
            'runId' => $run->runId,
            'workflowId' => $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            'createdAt' => $run->createdAt,
            'updatedAt' => $run->updatedAt,
            'pauseNodeId' => $run->pauseNodeId,
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
