<?php

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Support\FrameworkContext;
use Test\App;

/**
 * RateLimit 演示：Controller 内手写 vs 路由中间件。
 *
 * 生产请优先挂 {@see \Swoolefy\Http\Middleware\ApiRateLimiterMiddleware} /
 * {@see \Swoolefy\Http\Middleware\ApiUserRateLimiterMiddleware}，配置见 Config/rate_limit.php。
 */
class RateLimitController extends BController
{
    /**
     * 测试接口限流 RateLimit（Controller 内 DurationLimiter，与历史 Demo 一致）。
     *
     * Route: GET /api/rate-test1
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/rate-test1' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试接口限流 RateLimit（Controller）')]
    public function ratetest1(): array
    {
        $rateLimit = App::getRateLimit();
        $rateLimit->setRateKey('rate-order-search');
        $rateLimit->setLimitParams(5, 5);
        if (!$rateLimit->isLimit()) {
            return ['msg' => 'ok-' . rand(1, 1000)];
        }

        return ['msg' => '流量过大'];
    }

    /**
     * 路由维中间件限流通过后的业务响应。
     *
     * Route: GET /api/rate-test-api
     *
     ```bash
     # 连续请求超过 5次/5秒 → HTTP 429
     curl -i -X GET 'http://127.0.0.1:9501/api/rate-test-api' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'ApiRateLimiterMiddleware 演示')]
    public function rateTestApi(): array
    {
        return [
            'msg' => 'ok',
            'dimension' => 'api',
            'hint' => 'limited by ApiRateLimiterMiddleware',
        ];
    }

    /**
     * 用户维中间件限流通过后的业务响应（需 Bearer）。
     *
     * Route: GET /api/rate-test-user
     *
     ```bash
     curl -i -X GET 'http://127.0.0.1:9501/api/rate-test-user' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <jwt>'
     ```
     */
    #[ApiOperation(description: 'ApiUserRateLimiterMiddleware 演示')]
    public function rateTestUser(): array
    {
        return [
            'msg' => 'ok',
            'dimension' => 'api_user',
            'userId' => FrameworkContext::getUserId(),
            'hint' => 'limited by ApiUserRateLimiterMiddleware',
        ];
    }
}
