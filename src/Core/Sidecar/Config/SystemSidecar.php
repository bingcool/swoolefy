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
    // 启动时是否清理当前服务目录里没人监听的 .sock。默认 false，不清理。
    // 本进程 shutdown 时会删除自己登记的 sock；删不掉就留着。
    'sidecar_clean_sockets_on_start' => false,
    'sidecars' => [
        [
            'sidecar_name' => 'rocketmq',
            'sidecar_enable' => env('SIDECAR_ROCKETMQ_ENABLE', false),
            'sidecar_exec_binary' => dirname(__DIR__) . '/Bin/go-sidecar-' . (PHP_OS_FAMILY === 'Darwin' ? 'mac' : 'linux'),
            'sidecar_args' => [],
            // 只填目录，不能填 .sock 文件。空字符串用 {sidecar_runtime_dir}/sidecars_sock。
            // 框架再加一层服务目录：cron / script / daemon，都不是时用 cli。例如 /data/socks 在协议服务下是 /data/socks/cli。
            // 文件名由框架生成：{APP_NAME}-{Ymd}-{His}-{port}-{name}.sock，例如 Test-20261015-120412-9501-rocketmq.sock。
            'sidecar_socket_path' => '',
            'sidecar_plugins' => 'rocketmq',
            'sidecar_rocketmq_proxy_endpoint' => env('SIDECAR_ROCKETMQ_PROXY_ENDPOINT', ''),
            'sidecar_rocketmq_access_key' => env('SIDECAR_ROCKETMQ_ACCESS_KEY', ''),
            'sidecar_rocketmq_secret_key' => env('SIDECAR_ROCKETMQ_SECRET_KEY', ''),
            'sidecar_rocketmq_allowed_topics' => '*',
        ],
    ],
];
