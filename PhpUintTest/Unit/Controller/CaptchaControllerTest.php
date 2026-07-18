<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\CaptchaController::test
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/captcha/image' -H 'Accept: application/json'
 * ```
 */
final class CaptchaControllerTest extends ControllerHttpTestCase
{
    public function testImageReturnsDataUri(): void
    {
        $res = $this->getJson('/api/captcha/image');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $url = (string) ($data['url'] ?? '');
        $this->assertStringStartsWith('data:image/', $url);
    }
}
