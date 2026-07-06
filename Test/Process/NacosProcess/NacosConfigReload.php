<?php

declare(strict_types=1);

namespace Test\Process\NacosProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Nacos\Monitor\NacosMonitor;

/**
 * 自定义进程：委托 Swoolefy\Support\Nacos\Monitor 监听 Nacos 并重启服务。
 */
class NacosConfigReload extends AbstractProcess
{
    public function run(): void
    {
        NacosMonitor::run();
    }

    public function onReceive($msg, ...$args): void
    {
    }

    public function onShutDown(): void
    {
    }
}
