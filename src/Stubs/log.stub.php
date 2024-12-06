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

use Swoolefy\Util\Log;
use Swoolefy\Core\SystemEnv;
use Common\Library\Db\Mysql;
use Common\Library\Redis\Redis;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 用户行为记录的日志
    'log' => function($name) {
        $logger = new Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/info.log';
        }else if (SystemEnv::isCronService() || SystemEnv::cronScheduleScriptModel()) {
            $logFilePath = LOG_PATH.'/cron/info.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/info.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/info.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 用户行为记录的错误日志
    'error_log' => function($name) {
        $logger = new Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/error.log';
        }else if (SystemEnv::isCronService() || SystemEnv::cronScheduleScriptModel()) {
            $logFilePath = LOG_PATH.'/cron/error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 系统内置自动捕捉抛出异常错误日志
    'system_error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/system_error.log';
        }else if (SystemEnv::isCronService() || SystemEnv::cronScheduleScriptModel()) {
            $logFilePath = LOG_PATH.'/cron/system_error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/system_error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/system_error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    }
];