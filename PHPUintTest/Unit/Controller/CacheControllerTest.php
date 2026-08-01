<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\CacheController::test
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/cache/test' -H 'Accept: application/json'
 * ```
 */
#[\PHPUnit\Framework\Attributes\Group('redis')]
final class CacheControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/cache/test 缓存读写返回 name=bingcool。
     */
    public function testCacheSetGetName(): void
    {
        $res = $this->getJson('/api/cache/test');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        // return ['data' => cacheGet] → envelope data.data.name 或 data.name
        $name = $data['data']['name'] ?? $data['name'] ?? null;
        $this->assertSame('bingcool', $name);
    }
}
