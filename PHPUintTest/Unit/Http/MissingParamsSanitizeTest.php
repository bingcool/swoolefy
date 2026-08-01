<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Http;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Http\HttpRoute;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoole\Http\Status as HttpStatus;

/**
 * 阶段三 5.3（审计项 9）：缺参响应脱敏。
 * 目标：异常 message 不含完整 actionParams；context 仅缺参名/错误码/request_id。
 */
final class MissingParamsSanitizeTest extends TestCase
{
    /**
     * 测提交 password/token 但缺其他字段时，异常 message/context 不出现敏感值。
     * 对应问题：旧 message 拼接 json_encode(actionParams) 泄露凭证。
     */
    public function testMissingParamsExceptionDoesNotExposeSensitiveActionParams(): void
    {
        Coroutine\run(function (): void {
            Context::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, 'req-sanitize-1');

            $input = HttpRequestHarness::requestInput('POST', '/login', [], [
                'password' => 'super-secret-password',
                'token' => 'super-secret-token',
            ]);

            $route = (new ReflectionClass(HttpRoute::class))->newInstanceWithoutConstructor();
            $requestInputProp = new ReflectionProperty(HttpRoute::class, 'requestInput');
            $requestInputProp->setAccessible(true);
            $requestInputProp->setValue($route, $input);

            $controller = new class extends BController {
                public function __construct()
                {
                }

                public function login(string $username, string $password, string $token): void
                {
                }
            };

            $method = new ReflectionMethod(HttpRoute::class, 'bindActionParams');
            $method->setAccessible(true);

            try {
                $method->invoke($route, $controller, 'login', [
                    'password' => 'super-secret-password',
                    'token' => 'super-secret-token',
                ]);
                $this->fail('expected DispatchException');
            } catch (DispatchException $e) {
                $msg = $e->getMessage();
                $this->assertSame(HttpStatus::BAD_REQUEST, $e->getCode());
                $this->assertStringContainsString('username', $msg);
                $this->assertStringNotContainsString('super-secret-password', $msg);
                $this->assertStringNotContainsString('super-secret-token', $msg);
                $this->assertStringNotContainsString('actionParams', $msg);
                $this->assertStringNotContainsString('|||', $msg);

                $ctx = $e->getContextData();
                $this->assertSame('missing_params', $ctx['error_code']);
                $this->assertSame(['username'], $ctx['missing']);
                $this->assertSame('req-sanitize-1', $ctx['request_id']);
                $this->assertArrayNotHasKey('password', $ctx);
                $this->assertArrayNotHasKey('token', $ctx);
            }
        });
    }
}
