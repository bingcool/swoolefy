<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\Support\HttpRequestHarness;
use PhpUintTest\TestCase;
use Swoole\Coroutine;
use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;
use Swoolefy\Http\Middleware\ApiUserRateLimiterMiddleware;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\HeaderPropagation\HeaderContext;

/**
 * RateLimit 中间件键维度（不连 Redis）。
 */
final class ApiRateLimiterMiddlewareTest extends TestCase
{
    /**
     * 验证：路由维 key = api:{METHOD}:{path}。
     */
    public function testApiRateKeyUsesMethodAndPath(): void
    {
        $mw = new ApiRateLimiterMiddleware();
        $input = HttpRequestHarness::requestInput('GET', '/api/rate-test1');

        $this->assertSame('api:GET:/api/rate-test1', $mw->buildRateKey($input));
    }

    /**
     * 验证：用户维优先 Auth userId，清除后回落 ip/anon。
     */
    public function testApiUserRateKeyUsesUserThenIp(): void
    {
        $result = null;
        $error = null;
        Coroutine\run(static function () use (&$result, &$error): void {
            try {
                $mw = new ApiUserRateLimiterMiddleware();
                $input = HttpRequestHarness::requestInput('POST', '/api/v1/orders');

                FrameworkContext::setUser(new AuthUser(userId: 'u-42', roles: ['operator']));
                $withUser = $mw->buildRateKey($input);
                FrameworkContext::clearUser();
                HeaderContext::clear(); // setUser 回写的 x-user-id 需一并清掉
                $anonymous = $mw->buildRateKey($input);

                $result = [$withUser, $anonymous];
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }

        [$withUser, $anonymous] = $result;
        $this->assertSame('api_user:POST:/api/v1/orders:u:u-42', $withUser);
        $this->assertStringStartsWith('api_user:POST:/api/v1/orders:', $anonymous);
        $this->assertTrue(
            str_contains($anonymous, ':ip:') || str_contains($anonymous, ':anon:'),
            'anonymous subject should be ip or anon',
        );
    }
}
