<?php

declare(strict_types=1);

namespace PHPUintTest\Http;

/**
 * Outdoor 工作流 HTTP 黄金路径（useMock，不调 LLM）。
 *
 * 说明：Test 默认 MEMORY RunStore + worker_num>1 时，跨请求 status 可能 404；
 * 同请求内 cycling 响应已含完整 run 快照，故黄金路径以 start 契约为主。
 */
final class OutdoorWorkflowHttpTest extends HttpIntegrationTestCase
{
    public function testCyclingSunnyReturnsCompletedGoCycling(): void
    {
        $res = $this->postJson('/api/v1/outdoor/workflow/cycling', [
            'destination' => '深圳湾公园',
            'weatherHint' => 'sunny',
            'useMock' => true,
        ]);

        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertNotSame('', (string) ($data['runId'] ?? ''));
        $this->assertSame('outdoor_cycling', $data['workflowId'] ?? null);
        $this->assertSame('completed', $data['status'] ?? null);
        $this->assertSame('go_cycling', $data['decision'] ?? null);
        $this->assertTrue((bool) ($data['weatherGood'] ?? false));
    }

    public function testCyclingRainyReturnsStayHome(): void
    {
        $res = $this->postJson('/api/v1/outdoor/workflow/cycling', [
            'destination' => '深圳湾公园',
            'weatherHint' => 'rainy',
            'useMock' => true,
        ]);

        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertSame('completed', $data['status'] ?? null);
        $this->assertSame('stay_home', $data['decision'] ?? null);
        $this->assertFalse((bool) ($data['weatherGood'] ?? true));
    }

    public function testStatusMissingRunIdReturns400(): void
    {
        $res = $this->getJson('/api/v1/outdoor/workflow/status');
        $this->assertSame(400, $res['status']);
    }
}
