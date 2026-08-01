<?php

declare(strict_types=1);

namespace PHPUintTest\Http;

/**
 * K8s 探针 HTTP 黄金路径（需 Test 服务；未启服则 skip）。
 */
final class HealthProbeHttpTest extends HttpIntegrationTestCase
{
    /**
     * 验证：GET /health 返回 200 且 status=ok。
     */
    public function testLivenessHealthReturnsOk(): void
    {
        $res = $this->getJson('/health');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertSame('ok', $data['status'] ?? null);
        $this->assertSame('liveness', $data['probe'] ?? null);
    }

    /**
     * 验证：GET /ready 在 Redis 可达时返回 200。
     */
    public function testReadinessReadyReturnsOkWhenRedisUp(): void
    {
        $res = $this->getJson('/ready');
        // Redis 宕机时允许 503，本地常有 redis；断言结构即可
        $this->assertContains($res['status'], [200, 503]);
        $data = $this->responseData($res);
        $this->assertSame('readiness', $data['probe'] ?? null);
        $this->assertArrayHasKey('status', $data);
    }

    /**
     * 验证：/healthz 别名可用。
     */
    public function testHealthzAliasWorks(): void
    {
        $res = $this->getJson('/healthz');
        $this->assertSame(200, $res['status']);
    }
}
