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
