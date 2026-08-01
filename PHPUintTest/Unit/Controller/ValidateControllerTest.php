<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Controller;

/**
 * @see \Test\Controller\ValidateController::test1
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/validate-test1' -H 'Accept: application/json'
 * ```
 */
final class ValidateControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/validate-test1 缺嵌套字段时校验失败且 msg 含 require。
     */
    public function testValidateMissingNestedFieldFails(): void
    {
        $res = $this->getJson('/api/validate-test1');
        // 框架对 Validate 异常仍可能 HTTP 200，业务 code=-1
        $this->assertIsArray($res['body']);
        $code = $res['body']['code'] ?? null;
        $this->assertTrue(
            ($res['status'] >= 400 && $res['status'] < 500) || $code === -1 || $code === 400,
            'expected validation failure, status=' . $res['status'] . ' code=' . var_export($code, true),
        );
        $msg = strtolower((string) ($res['body']['msg'] ?? ''));
        $this->assertStringContainsString('require', $msg);
    }
}
