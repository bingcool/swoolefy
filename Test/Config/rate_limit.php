<?php

declare(strict_types=1);

/**
 * Test 应用 HTTP RateLimit 配置。
 *
 * @see src/Stubs/rate_limit.conf.stub.php
 */

return [
    'rate_limit' => [
        'enabled' => env('RATE_LIMIT_ENABLED', true),
        'redis_component' => env('RATE_LIMIT_REDIS_COMPONENT', 'redis'),
        'key_prefix' => env('RATE_LIMIT_KEY_PREFIX', 'http:'),
        'default_limit_num' => (int) env('RATE_LIMIT_DEFAULT_NUM', 60),
        'default_window_seconds' => (int) env('RATE_LIMIT_DEFAULT_WINDOW', 60),
        'http_status' => (int) env('RATE_LIMIT_HTTP_STATUS', 429),
        'message' => env('RATE_LIMIT_MESSAGE', 'Too Many Requests'),
        'add_headers' => env('RATE_LIMIT_ADD_HEADERS', true),
        'routes' => [
            '/api/rate-test1' => ['limit_num' => 5, 'window_seconds' => 5],
        ],
        'user' => [
            'anonymous_key' => 'guest',
            'use_client_ip_when_anonymous' => true,
            'skip_when_unauthenticated' => false,
        ],
    ],
];
