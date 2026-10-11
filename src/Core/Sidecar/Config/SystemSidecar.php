<?php

declare(strict_types=1);

/**
 * 框架自带的 Sidecar。应用层追加的进程写在 APP_PATH/Config/sidecar.php，由 SidecarConfig 合并进来。
 *
 * sidecar_max_frame_bytes、sidecar_default_timeout_ms、sidecar_max_timeout_ms、
 * sidecar_max_inflight、sidecar_shutdown_grace_ms 不写在每一项里。缺省时用 .env 的公共配置。
 * rocketmq 是否拉起由 SIDECAR_ROCKETMQ_ENABLE 决定，缺省为关闭。
 */

return [
    'sidecar_runtime_dir' => APP_PATH,
    'sidecars' => [
        [
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => env('SIDECAR_ROCKETMQ_ENABLE', false),
            'sidecar_exec_binary' => dirname(__DIR__) . '/Bin/go-sidecar-' . (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux'),
            'sidecar_args' => [],
            'sidecar_socket_path' => '',
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => env('SIDECAR_ROCKETMQ_PROXY_ENDPOINT', ''),
            'sidecar_rocketmq_access_key' => env('SIDECAR_ROCKETMQ_ACCESS_KEY', ''),
            'sidecar_rocketmq_secret_key' => env('SIDECAR_ROCKETMQ_SECRET_KEY', ''),
            'sidecar_rocketmq_allowed_topics' => '*',
        ],
    ],
];
