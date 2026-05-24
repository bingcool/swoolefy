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
    'queue' => function() {
//        $redis = Application::getApp()->get('redis')->getObject();
//        return new \Swoolefy\Library\Queues\Queue($redis,\Test\Process\ListProcess\RedisList::queue_order_list);

        $predis = Application::getApp()->get('predis')->getObject();
        return new \Swoolefy\Library\Queues\Queue($predis,\Test\Process\QueueProcess\Queue::queue_order_list);
    },

    'delayQueue' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        return new \Swoolefy\Library\Queues\RedisDelayQueue($redis,\Test\Process\QueueProcess\Queue::queue_order_list);

//        $predis = Application::getApp()->get('predis')->getObject();
//        return new \Swoolefy\Library\Queues\PredisDelayQueue($predis,\Test\Process\QueueProcess\Queue::queue_order_list);
    }

];