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
    // 组件名 queue。生产者、消费者的 $queue_component 写这个名字，不写下面的 Redis 键。
    // Redis 键是 queue:order:list。List 用 predis，Library\Queues\Queue 会 LPUSH / BRPOP。
    'queue' => function() {
//        $redis = Application::getApp()->get('redis')->getObject();
//        return new \Swoolefy\Library\Queues\Queue($redis,\Test\Process\ListProcess\RedisList::queue_order_list);

        $predis = Application::getApp()->get('predis')->getObject();
        return new \Swoolefy\Library\Queues\Queue($predis, \Test\Process\ListProcess\RedisList::queue_order_list);
    },

    // 组件名 delayQueue。$queue_component 写 delayQueue。
    // Redis 键是 queue:order:delay，和上面的 List 键分开。结构是 ZSET，score 为可见时间。
    // RedisDelayQueue 只能用 phpredis，不能传入 Predis。
    'delayQueue' => function() {
        $redis = Application::getApp()->get('redis')->getObject();
        return new \Swoolefy\Library\Queues\RedisDelayQueue($redis,\Test\Process\DelayQueueProcess\DelayConsumerQueue::queue_order_list);

//        $predis = Application::getApp()->get('predis')->getObject();
//        return new \Swoolefy\Library\Queues\PredisDelayQueue($predis,\Test\Process\DelayQueueProcess\Queue::queue_order_list);
    }

];