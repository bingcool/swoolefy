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

/**
 * Nacos 配置监听入口（供自定义进程调用）。
 */
final class NacosMonitor
{
    public static function run(): void
    {
        $config = MonitorConfig::load();
        $handler = new ConfigChangeHandler($config);
        $watcher = new ConfigWatcher($config, $handler);
        $watcher->run();
    }

    public static function loadConfig(): MonitorConfig
    {
        return MonitorConfig::load();
    }
}
