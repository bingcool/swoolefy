<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\AuthUserController::me
 *
 * ```bash
 * curl -i -X GET 'http://127.0.0.1:9501/api/auth-user/me' -H 'Accept: application/json'
 * ```
 */
final class AuthUserControllerTest extends ControllerHttpTestCase
{
    public function testMeWithoutBearerReturns401(): void
    {
        $res = $this->getJson('/api/auth-user/me');
        $this->assertSame(401, $res['status']);
        $this->assertIsArray($res['body']);
        $this->assertSame(401, $res['body']['code'] ?? null);
    }

    public function testMeWithInvalidBearerReturns401(): void
    {
        $res = $this->getJson('/api/auth-user/me', [
            'Authorization' => 'Bearer not.a.jwt',
        ]);
        $this->assertSame(401, $res['status']);
    }
}
