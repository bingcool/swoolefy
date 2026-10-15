<?php

declare(strict_types=1);

/**
 * 业务 Sidecar（create 时复制为 Config/sidecar.php）。
 *
 * 框架自带的 RocketMQ 进程在 Core/Sidecar/Config/SystemSidecar.php。
 * 不写 sidecars 表示不追加进程。以后业务要再拉进程时，加上 sidecars。
 * 单项没写的帧大小、超时、inflight、停机等待，使用 .env 里的公共配置。
 */

return [
    // 启动时是否清理当前服务目录里没人监听的 .sock。默认 false，不清理。
    // 本进程 shutdown 时会删除自己登记的 sock；删不掉就留着。
    'sidecar_clean_sockets_on_start' => false,
];
