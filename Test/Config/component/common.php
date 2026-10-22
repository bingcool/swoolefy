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

use Swoolefy\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [

    'uuid' => function() use($dc) {
        $redis = Application::getApp()->get('redis')->getObject();
        return \Swoolefy\Library\Uuid\UuidManager::getInstance($redis, 'uuid-key');
    },

    'rateLimit' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        $rateLimit =  new \Swoolefy\Library\RateLimit\DurationLimiter($redis);
        return $rateLimit;
    },

    // 锁实例持有 token/租期等可变状态，必须按使用方（协程）新建，不能跨协程复用
    'redis-order-lock' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        return new \Swoolefy\Library\Lock\RedisMutex($redis, 'order_lock', 5.0, 30.0);
    },

    'predis-order-lock' => function() {
        $redis = Application::getApp()->get('predis')->getObject();
        return new \Swoolefy\Library\Lock\RedisMutex($redis, 'order_lock-1', 5.0, 30.0);
    },

    'session' => function() {
        $session = new \Swoolefy\Core\Session();
        return $session;
    },

];