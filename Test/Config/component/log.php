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

use Common\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;
use Swoolefy\Core\SystemEnv;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 用户行为记录的info日志
    'info_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
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
        // 日志文件名按小时分文件记录
        $logger->enableHourly();
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 用户行为记录的error日志
    'error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
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

    // 系统捕捉抛出异常错误日志
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