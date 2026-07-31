<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * LogController 四个日志通道接口的真实 HTTP 黄金路径。
 *
 * @see \Test\Controller\LogController
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/log/info' -H 'Accept: application/json'
 * curl -X GET 'http://127.0.0.1:9501/api/log/error' -H 'Accept: application/json'
 * curl -X GET 'http://127.0.0.1:9501/api/log/system-error' -H 'Accept: application/json'
 * curl -X GET 'http://127.0.0.1:9501/api/log/goapp' -H 'Accept: application/json'
 * ```
 */
final class LogControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/log/info 写入 info_log 并返回 ok/channel/wrote/log_file。
     */
    public function testInfoWritesInfoLog(): void
    {
        $res = $this->getJson('/api/log/info');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertTrue((bool) ($data['ok'] ?? false), json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('info_log', $data['channel'] ?? null);
        $this->assertNotSame('', (string) ($data['wrote'] ?? ''));
        $this->assertArrayHasKey('log_file', $data);
        $this->assertStringContainsString('LogController::info', (string) $data['wrote']);
    }

    /**
     * 验证：GET /api/log/error 写入 error_log 并返回 ok/channel/wrote/log_file。
     */
    public function testErrorWritesErrorLog(): void
    {
        $res = $this->getJson('/api/log/error');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertTrue((bool) ($data['ok'] ?? false), json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('error_log', $data['channel'] ?? null);
        $this->assertNotSame('', (string) ($data['wrote'] ?? ''));
        $this->assertArrayHasKey('log_file', $data);
        $this->assertStringContainsString('LogController::error', (string) $data['wrote']);
    }

    /**
     * 验证：GET /api/log/system-error 走框架异常通道（错误 JSON / 非 200），且进程不挂。
     *
     * RuntimeException code=0 → 信封 code=-1；HTTP 通常仍为 200（非 4xx/5xx code 时不改状态）。
     */
    public function testSystemErrorReturnsErrorJsonAndKeepsProcessAlive(): void
    {
        $res = $this->getJson('/api/log/system-error');
        $this->assertIsArray($res['body'], 'exception channel should return JSON');
        $body = $res['body'];
        $code = $body['code'] ?? null;
        $msg = (string) ($body['msg'] ?? '');

        $this->assertTrue(
            $res['status'] !== 200 || ($code !== null && $code !== 0),
            'expected error JSON (non-zero code) or non-200 status, got status='
            . $res['status'] . ' code=' . var_export($code, true),
        );
        $this->assertStringContainsString('LogController::systemError', $msg);

        // 异常处理后进程应仍可服务：再打一次 info 接口
        $alive = $this->getJson('/api/log/info');
        $this->assertSame(200, $alive['status']);
        $aliveData = $this->responseData($alive);
        $this->assertTrue((bool) ($aliveData['ok'] ?? false));
    }

    /**
     * 验证：GET /api/log/goapp 嵌套协程写 info/error 日志并返回双层 cid 与 wrote。
     */
    public function testGoAppNestedCoroutineLogs(): void
    {
        $res = $this->getJson('/api/log/goapp');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);
        $this->assertTrue((bool) ($data['ok'] ?? false), json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        $this->assertArrayHasKey('parent_cid', $data);
        $this->assertArrayHasKey('l1_cid', $data);
        $this->assertArrayHasKey('l2_cid', $data);
        $this->assertStringContainsString('goApp L1 info', (string) ($data['info_wrote'] ?? ''));
        $this->assertStringContainsString('goApp L2 error', (string) ($data['error_wrote'] ?? ''));
    }
}
