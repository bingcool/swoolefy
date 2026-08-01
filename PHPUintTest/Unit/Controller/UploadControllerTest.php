<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\UploadController::single
 *
 * ```bash
 * curl -X POST 'http://127.0.0.1:9501/api/upload/single'
 * ```
 */
final class UploadControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：POST /api/upload/single 无文件时返回 code=400 且提示 required。
     */
    public function testSingleWithoutFileReturns400Contract(): void
    {
        $res = $this->postMultipart('/api/upload/single');
        $this->assertSame(200, $res['status']);
        $body = $res['body'];
        $this->assertIsArray($body);
        // 控制器直接返回 {code,msg}；经信封时落在 data
        $payload = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $this->assertSame(400, $payload['code'] ?? ($body['code'] ?? null));
        $this->assertStringContainsString('required', strtolower((string) ($payload['msg'] ?? $body['msg'] ?? '')));
    }
}
