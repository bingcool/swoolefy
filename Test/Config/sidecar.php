<?php

declare(strict_types=1);

/**
 * 外部 Sidecar 与 RocketMQ 消费进程。
 *
 * 协议 conf.php 通过 {@see \Swoolefy\Core\Sidecar\SidecarConfig::fromApp()} 并入。
 * sidecar_enable 为 false 时不拉起，也不检查二进制。
 * 代理地址和密钥来自 APP_PATH/.env，不写进本文件。
 */

return [
    'sidecar_runtime_dir' => APP_PATH,
    'sidecars' => [
        [
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => true,
            'sidecar_exec_binary' => START_DIR_ROOT.'/go-sidecar',
            'sidecar_args' => [],
            'sidecar_socket_path' => '',
            'sidecar_max_frame_bytes' => 16777216,
            'sidecar_default_timeout_ms' => 3000,
            'sidecar_max_timeout_ms' => 30000,
            'sidecar_max_inflight' => 128,
            'sidecar_shutdown_grace_ms' => 5000,
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => env('SIDECAR_ROCKETMQ_PROXY_ENDPOINT', ''),
            'sidecar_rocketmq_access_key' => env('SIDECAR_ROCKETMQ_ACCESS_KEY', ''),
            'sidecar_rocketmq_secret_key' => env('SIDECAR_ROCKETMQ_SECRET_KEY', ''),
            'sidecar_rocketmq_allowed_topics' => '*',
        ],
    ],
    'rocketmq_consumers' => [],
];
