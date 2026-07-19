<?php

namespace Test\Router;

use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;
use Swoolefy\Http\Middleware\ApiUserRateLimiterMiddleware;
use Swoolefy\Http\Middleware\AuthenticateMiddleware;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * RateLimit 演示路由。
 *
 * - rate-test1：Controller 内手写 DurationLimiter（历史 Demo）
 * - rate-test-api：路由维中间件（配额见 RouteOption 或 Config/rate_limit.php）
 * - rate-test-user：路由+用户维（挂在 Authenticate 之后）
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/rate-test1 — Controller 内限流 Demo
    Route::get('/rate-test1', [
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'ratetest1'],
    ]);

    // GET /api/rate-test-api — ApiRateLimiterMiddleware（5次/5秒，与 Controller Demo 同量级）
    Route::get('/rate-test-api', [
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'rateTestApi'],
    ])->withRateLimiterMiddleware(ApiRateLimiterMiddleware::class, 5, 5);

    // GET /api/rate-test-user — ApiUserRateLimiterMiddleware（需 Bearer）
    Route::get('/rate-test-user', [
        'middleware' => [
            AuthenticateMiddleware::class,
        ],
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'rateTestUser'],
    ])->withRateLimiterMiddleware(
        ApiUserRateLimiterMiddleware::class,
        5,
        5,
        AuthenticateMiddleware::class,
    );

});
