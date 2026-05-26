<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 配置监听入口（供自定义进程调用）。
 */
final class NacosMonitor
{
    public static function run(string $appPath, string $nacosFilePath, ?Log $logger = null): void
    {
        $logger ??= LogManager::getInstance()->getLogger('nacos_log');
        if (!$logger instanceof Log) {
            throw NacosMonitorException::throw('nacos_log logger is not registered');
        }

        $config = MonitorConfig::load($appPath, $nacosFilePath);
        $handler = new ConfigChangeHandler($config, $logger);
        $watcher = new ConfigWatcher($config, $handler, $logger);
        $watcher->run();
    }

    public static function loadConfig(?string $appPath = null, string $nacosFilePath, ?Log $logger = null): MonitorConfig
    {
        return MonitorConfig::load($appPath, $nacosFilePath, $logger);
    }
}
