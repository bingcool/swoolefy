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

use Swoolefy\Library\Amqp\AmqpStreamConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Swoolefy\Core\Application;
use Test\Config\AmqpConfig;
use Test\Config\KafkaConfig;

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 组件名 queue。只返回 List 用的 Redis 连接，默认 predis。
    // Queue 和 Redis 键由生产者、消费者自己创建。换一条 List 时覆盖 $queueKey，不要在这里写死键。
    'queue' => function () {
        return Application::getApp()->get('predis')->getObject();
    },

    // 组件名 delayQueue。只返回 phpredis 连接。RedisDelayQueue 不能用 Predis。
    // RedisDelayQueue 和 ZSET 键由生产者、消费者自己创建。
    'delayQueue' => function () {
        return Application::getApp()->get('redis')->getObject();
    },
];