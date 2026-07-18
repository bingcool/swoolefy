<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\TokenController::jwt
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/token/jwt' -H 'Accept: application/json'
 * ```
 */
final class TokenControllerTest extends ControllerHttpTestCase
{
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
