<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\TokenController::jwt
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/token/jwt' -H 'Accept: application/json'
 * ```
 */
final class TokenControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/token/jwt 返回 HTTP 200 且 token 为三段式 JWT。
     */
    public function testJwtReturnsThreePartToken(): void
    {
        $res = $this->getJson('/api/token/jwt');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $token = (string) ($data['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertCount(3, explode('.', $token));
    }
}
