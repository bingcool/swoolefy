<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\AuthUserController::me
 *
 * ```bash
 * curl -i -X GET 'http://127.0.0.1:9501/api/auth-user/me' -H 'Accept: application/json'
 * ```
 */
final class AuthUserControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/auth-user/me 无 Bearer 时返回 HTTP 401 及业务 code=401。
     */
    public function testMeWithoutBearerReturns401(): void
    {
        $res = $this->getJson('/api/auth-user/me');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['body']);
        $this->assertSame(401, $res['body']['code'] ?? null);
    }

    /**
     * 验证：GET /api/auth-user/me 携带无效 JWT 时返回 HTTP 401。
     */
    public function testMeWithInvalidBearerReturns401(): void
    {
        $res = $this->getJson('/api/auth-user/me', [
            'Authorization' => 'Bearer not.a.jwt',
        ]);
        $this->assertSame(401, $res['status']);
    }
}
