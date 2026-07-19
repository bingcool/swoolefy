<?php

declare(strict_types=1);

/**
 * K8s HTTP 健康探针配置（create 时复制为 Config/health.php）
 *
 * | 探针 | 默认路径 | kubelet |
 * |------|----------|---------|
 * | liveness | /health、/healthz、/livez | livenessProbe.httpGet.path |
 * | readiness | /ready、/readyz | readinessProbe.httpGet.path |
 *
 * 路由注册：`Swoolefy\Http\Health\HealthRoutes::register()`（见 Router stub）。
 *
 * 与 ProductionHealthCheck（CLI 配置体检）互补，不互相替代。
 *
 * @see \Swoolefy\Http\Health\HealthController
 * @see \Swoolefy\Http\Health\HealthProbe
 */

return [
    'health' => [
        'enabled' => env('HEALTH_PROBE_ENABLED', true),
        'liveness_path' => env('HEALTH_LIVENESS_PATH', '/health'),
        'readiness_path' => env('HEALTH_READINESS_PATH', '/ready'),
        'aliases' => [
            'liveness' => ['/healthz', '/livez'],
            'readiness' => ['/readyz'],
        ],
        // 非生产默认带 checks 明细；生产默认关闭（env 可开）
        'include_details' => env('HEALTH_INCLUDE_DETAILS', true),
        'include_details_in_prd' => env('HEALTH_INCLUDE_DETAILS_IN_PRD', false),

        // liveness：空数组 = 仅进程存活（推荐，避免依赖抖动杀 Pod）
        'liveness_checks' => [
            // ['type' => 'process'],
        ],

        // readiness：接流量前检查依赖（按环境裁剪）
        'readiness_checks' => [
            ['type' => 'redis', 'component' => 'redis', 'name' => 'redis'],
            // ['type' => 'database', 'component' => 'db', 'name' => 'database'],
            // ['type' => 'class', 'class' => \App\Health\CustomCheck::class],
        ],
    ],
];
