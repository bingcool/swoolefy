<?php

declare(strict_types=1);

/**
 * 业务可能需要实现自己的Sidecar。
 *
 * 框架自带系统级别的 RocketMQ 进程在 @see \Swoolefy\Core/Sidecar/Config/SystemSidecar.php
 * 这里返回 [] 表示不追加。以后业务要再拉进程时，返回 sidecars。
 * 单项没写的帧大小、超时、inflight、停机等待，使用 .env 里的公共配置。
 */

return [];
