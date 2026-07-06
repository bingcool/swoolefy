<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Core\Log\LogManager;
use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 模块统一日志通道（nacos_log）。
 */
final class NacosLogger
{
    public const CHANNEL = 'nacos_log';

    public static function get(): Log
    {
        $logger = LogManager::getInstance()->getLogger(self::CHANNEL);
        if (!$logger instanceof Log) {
            throw NacosMonitorException::throw('nacos_log logger is not registered');
        }

        return $logger;
    }
}
