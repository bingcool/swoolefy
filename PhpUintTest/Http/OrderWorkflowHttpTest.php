<?php

declare(strict_types=1);

namespace PhpUintTest\Http;

/**
 * Order Demo HTTP 黄金路径：process（mock 批准）+ saga（补偿路径）。
 *
 * 同请求内返回完整 run 快照，不依赖跨 Worker RunStore。
 */
final class OrderWorkflowHttpTest extends HttpIntegrationTestCase
{
    /**
     * 验证：mock 高置信度批准走完 process，orderStatus=completed。
     */
    public function testProcessApprovedMockCompletes(): void
    {
        $res = $this->postJson('/api/v1/order/workflow/process', [
            'orderId' => 'ORD-HTTP-' . bin2hex(random_bytes(3)),
            'userId' => 'u-http',
            'amount' => 120.0,
            'mockDecision' => [
                'approved' => true,
                'confidence' => 0.95,
                'reason' => 'http golden path approve',
            ],
        ]);

        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertNotSame('', (string) ($data['runId'] ?? ''));
        $this->assertSame('order_processing', $data['workflowId'] ?? null);
        $this->assertSame('completed', $data['status'] ?? null);
        $this->assertSame('completed', $data['orderStatus'] ?? null);
        $this->assertSame('captured', $data['paymentStatus'] ?? null);
    }

    /**
     * 验证：saga 演示在 notify_fail 后以业务错误返回（Demo Controller 映射为 400）。
     *
     * 补偿已在引擎内完成；HTTP 契约为非 200 错误信封，而非 silent 200。
     */
    public function testSagaCompensationPathReturnsClientError(): void
    {
        $res = $this->postJson('/api/v1/order/workflow/saga', [
            'orderId' => 'ORD-SAGA-HTTP-' . bin2hex(random_bytes(3)),
            'userId' => 'u-http',
            'amount' => 50.0,
            'items' => [['sku' => 'SKU-SAGA', 'qty' => 1]],
        ]);

        $this->assertSame(400, $res['status'], 'Demo saga maps WorkflowException to HTTP 400');
        $this->assertIsArray($res['body']);
    }

    /**
     * 验证：status 缺 runId 返回 400。
     */
    public function testStatusMissingRunIdReturns400(): void
    {
        $res = $this->getJson('/api/v1/order/workflow/status');
        $this->assertSame(400, $res['status']);
    }
}
