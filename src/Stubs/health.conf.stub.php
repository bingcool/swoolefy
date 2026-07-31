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

        /**
         * 单项检查独立短超时（秒）。须 > 0；单条 def 可用 timeout_seconds 覆盖。
         * 探针 TimeoutGuard + 底层客户端读超时共用此预算。
         * env: HEALTH_CHECK_TIMEOUT_SECONDS
         */
        'check_timeout_seconds' => (int) env('HEALTH_CHECK_TIMEOUT_SECONDS', 2),

        // liveness：空数组 = 仅进程存活（推荐，避免依赖抖动杀 Pod）
        'liveness_checks' => [
            // ['type' => 'process'],
        ],

        // readiness：接流量前检查依赖（按环境裁剪）；未知 type 启动失败
        'readiness_checks' => [
            ['type' => 'redis', 'component' => 'redis', 'name' => 'redis'],
            // ['type' => 'database', 'component' => 'db', 'name' => 'database'],
            // FileStorageSystem：local / aws_s3 / aliyun_oss / tengxun_cos（及 fake）
            // disk = Config/file_storage_system.php 中的 provider 键；省略则用 default_provider
            // ['type' => 'file_storage', 'component' => 'file_storage', 'disk' => 'local', 'name' => 'storage-local'],
            // ['type' => 'file_storage', 'component' => 'file_storage', 'disk' => 'aws_s3', 'name' => 'storage-s3'],
            // ['type' => 'file_storage', 'component' => 'file_storage', 'disk' => 'aliyun_oss', 'name' => 'storage-oss'],
            // ['type' => 'file_storage', 'component' => 'file_storage', 'disk' => 'tengxun_cos', 'name' => 'storage-cos'],
            // ['type' => 'class', 'class' => \App\Health\CustomCheck::class],
        ],
    ],
];
