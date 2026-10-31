<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Core\Process\AbstractProcess;

/**
 * 内置进程：application.yaml 的 nacos.enable_nacos_config_change_restart 为真时注册，
 * 监听配置中心变更并把重启交给 {@see ConfigChangeHandler}。
 */
class NacosConfigReloadProcess extends AbstractProcess
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
