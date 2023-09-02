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

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 用户行为记录的日志
    'log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon.log';
        }else if (isScriptService()) {
            $logFilePath = LOG_PATH.'/script.log';
        }else if (isCronService()) {
            $logFilePath = LOG_PATH.'/cron.log';
        } else {
            $logFilePath = LOG_PATH.'/runtime.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 系统捕捉异常错误日志
    'error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon_error.log';
        }else if (isScriptService()) {
            $logFilePath = LOG_PATH.'/script_error.log';
        }else if (isCronService()) {
            $logFilePath = LOG_PATH.'/cron_error.log';
        } else {
            $logFilePath = LOG_PATH.'/error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    }
];