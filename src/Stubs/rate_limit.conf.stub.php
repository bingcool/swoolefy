<?php

declare(strict_types=1);

/**
 * HTTP RateLimit 配置（create 时复制为 Config/rate_limit.php）
 *
 * 中间件：
 *   - ApiRateLimiterMiddleware      路由维度（method + path）
 *   - ApiUserRateLimiterMiddleware  路由 + 用户维度（推荐挂在 Authenticate 之后）
 *
 * Redis：使用 Application 组件（默认 redis），滑动窗口算法见 Library DurationLimiter。
 *
 * 路由级覆盖（优先于本文件 default，但低于 RouteOption::withRateLimiterMiddleware 的 limit/window）：
 *   Route::get(...)->withRateLimiterMiddleware(ApiRateLimiterMiddleware::class, 100, 60);
 *
 * @see \Swoolefy\Http\Middleware\ApiRateLimiterMiddleware
 * @see \Swoolefy\Http\Middleware\ApiUserRateLimiterMiddleware
 */

return [
    'rate_limit' => [
        // env RATE_LIMIT_ENABLED
        'enabled' => env('RATE_LIMIT_ENABLED', true),
        // Application::getApp()->get(...) 组件名
        'redis_component' => env('RATE_LIMIT_REDIS_COMPONENT', 'redis'),
        // 逻辑键前缀（最终 Redis key = DurationLimiter::_rate_limit: + prefix + buildRateKey）
        'key_prefix' => env('RATE_LIMIT_KEY_PREFIX', 'http:'),
        // 未传 RouteOption / 未匹配 routes 时的默认配额
        'default_limit_num' => (int) env('RATE_LIMIT_DEFAULT_NUM', 60),
        'default_window_seconds' => (int) env('RATE_LIMIT_DEFAULT_WINDOW', 60),
        'http_status' => (int) env('RATE_LIMIT_HTTP_STATUS', 429),
        'message' => env('RATE_LIMIT_MESSAGE', 'Too Many Requests'),
        // 写入 X-RateLimit-* / Retry-After
        'add_headers' => env('RATE_LIMIT_ADD_HEADERS', true),

        // path 精确或前缀（以 * 结尾）；最长前缀优先
        'routes' => [
            // '/api/rate-test1' => ['limit_num' => 5, 'window_seconds' => 5],
            // '/api/v1/workflow/*' => ['limit' => 30, 'window' => 60],
        ],

        'user' => [
            // 匿名且无 IP 时的占位
            'anonymous_key' => env('RATE_LIMIT_ANONYMOUS_KEY', 'guest'),
            // 未登录时用 ClientIP 作为 subject
            'use_client_ip_when_anonymous' => env('RATE_LIMIT_USE_IP_WHEN_ANONYMOUS', true),
            // true：未登录则跳过 ApiUserRateLimiter（适合强制登录路由）
            'skip_when_unauthenticated' => env('RATE_LIMIT_SKIP_WHEN_UNAUTHENTICATED', false),
        ],
    ],
];
