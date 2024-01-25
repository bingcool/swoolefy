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
        $logger->setRotateDay(1);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon_info.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script_info.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron_info.log';
        } else {
            $logFilePath = LOG_PATH.'/cli_info.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 用户行为记录的error日志
    'error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon_error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script_error.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron_error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli_error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 系统捕捉抛出异常错误日志
    'system_error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon_system_error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script_system_error.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron_system_error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli_system_error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    }
];