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

    'uuid' => function() use($dc) {
        $redis = Application::getApp()->get('redis')->getObject();
        return \Common\Library\Uuid\UuidManager::getInstance($redis, 'uuid-key');
    },

    'rateLimit' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        $rateLimit =  new \Common\Library\RateLimit\DurationLimiter($redis);
        return $rateLimit;
    },

    'redis-order-lock' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        $lock = new \Common\Library\Lock\PHPRedisMutex([$redis],'order_lock', 5);
        return $lock;
    },

    'predis-order-lock' => function() {
        $redis = Application::getApp()->get('predis')->getObject();
        $lock = new \Common\Library\Lock\PredisMutex([$redis],'order_lock-1', 5);
        return $lock;
    },

    'session' => function() {
        $session = new \Swoolefy\Core\Session();
        return $session;
    },

];