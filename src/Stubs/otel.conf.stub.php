<?php

declare(strict_types=1);

/**
 * HTTP OpenTelemetry 最小采集配置（create 时复制为 Config/otel.php）
 *
 * 全局总开关仍由 `.env` 的 OTEL_PHP_AUTOLOAD_ENABLED 控制。
 * 路由级关闭：RouteOption::enableOpenTelemetry(false)。
 *
 * @see \Swoolefy\Http\OpenTelemetry\OpenTelemetryConfig
 * @see \Swoolefy\Http\OpenTelemetry\OpenTelemetryHttpCollector
 */

return [
    'otel' => [
        // 敏感字段脱敏（Authorization/Cookie/Token/密码等），默认启用
        // env: OTEL_ATTRIBUTE_SANITIZE_ENABLED
        'sanitize_enabled' => env('OTEL_ATTRIBUTE_SANITIZE_ENABLED', true),

        // attribute 最大长度；空/0/未设置 = 不限制；超出截断并标记 ...[TRUNCATED]
        // env: OTEL_ATTRIBUTE_MAX_LENGTH
        'attribute_max_length' => env('OTEL_ATTRIBUTE_MAX_LENGTH', null),

        // 是否采集 request body，默认采集
        // env: OTEL_COLLECT_REQUEST_BODY
        'collect_request_body' => env('OTEL_COLLECT_REQUEST_BODY', true),
    ],
];
