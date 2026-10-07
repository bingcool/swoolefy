<?php

/**
 * 示例 Fanout 交换机（广播：消息进入 exchange 后复制到所有绑定队列）。
 *
 * binding_key / routing_key 留空。发布端可只声明 exchange；每个消费端绑定独立 queue。
 *
 * 本文件组件：
 *   demoFanoutPublish — 仅 exchange，用于 publish 广播
 *   demoFanoutQueue   — 一个消费队列示例；再加队列则复制该闭包并改 queue 名与 consumer_tag
 *
 * exchange、queue 用局部变量，勿用全局 const，否则 reloadGlobalConf 重复 include 会报错。
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Core\Application;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpFanoutQueue;

/** 交换机名（仅本文件） */
$exchangeDemoFanout = 'demo_exchange_fanout';

/** 消费队列名（仅本文件） */
$queueDemoFanout = 'demo_queue_fanout';

$propertyDemoFanout = \__APP_NAMESPACE__\Config\AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::FANOUT,
    'binding_key' => '',
    'routing_key' => '',
    'consumer_tag' => 'demoFanoutConsumer',
]);

return [
    // 发布端：不绑队列，只声明交换机
    'demoFanoutPublish' => static function () use ($exchangeDemoFanout) {
        $connection = Application::getApp()->get('amqpConnection')->getObject();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeDemoFanout;

        return new AmqpFanoutQueue($connection, $amqpConfig);
    },

    // 消费端：绑定独立队列，收到该交换机上的全部消息
    'demoFanoutQueue' => static function () use ($exchangeDemoFanout, $queueDemoFanout, $propertyDemoFanout) {
        $connection = Application::getApp()->get('amqpConnection')->getObject();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeDemoFanout;
        $amqpConfig->queueName = $queueDemoFanout;
        \__APP_NAMESPACE__\Config\AmqpConfig::applyQueueProperty($amqpConfig, $propertyDemoFanout, true);

        return new AmqpFanoutQueue($connection, $amqpConfig);
    },
];
