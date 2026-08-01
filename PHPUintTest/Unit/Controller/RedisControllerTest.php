<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\RedisController
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/redis/test' -H 'Accept: application/json'
 * curl -X GET 'http://127.0.0.1:9501/api/redis/predis' -H 'Accept: application/json'
 * ```
 */
#[\PHPUnit\Framework\Attributes\Group('redis')]
final class RedisControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/redis/test Redis 读写往返成功且 value 非空。
     */
    public function testRedisRoundTrip(): void
    {
        $res = $this->getJson('/api/redis/test');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertArrayHasKey('value', $data);
        $this->assertNotSame('', (string) $data['value']);
    }

    /**
     * 验证：GET /api/redis/predis Predis 读写往返成功且 value 非空。
     */
    public function testPredisRoundTrip(): void
    {
        $res = $this->getJson('/api/redis/predis');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertArrayHasKey('value', $data);
        $this->assertNotSame('', (string) $data['value']);
    }
}
