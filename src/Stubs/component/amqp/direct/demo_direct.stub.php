<?php

/**
 * 示例 Direct 交换机（发布与消费同一 AmqpDirectQueue，按组件名区分用途）。
 *
 * Direct：binding_key 与 routing_key 必须一致且唯一，消息只进入匹配的队列。
 *
 * 本文件组件：
 *   demoDirectQueue — 示例队列（consumer_tag 按进程追加 pid，避免多进程冲突）
 *
 * 新增队列：复制一段 return 闭包，改 exchange / queue / $property 与组件名。
 * exchange、queue 用局部变量，勿用全局 const，否则 reloadGlobalConf 重复 include 会报错。
 *
 * 连接：Application::getApp()->get('amqpConnection')->getObject()
 * 公共默认：Config\AmqpConfig::queueProperty() / applyQueueProperty()
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Core\Application;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpDirectQueue;

/** 交换机名（仅本文件） */
$exchangeDemoDirect = 'demo_exchange_direct';

/** 队列名（仅本文件） */
$queueDemoDirect = 'demo_queue_direct';

$propertyDemoDirect = \__APP_NAMESPACE__\Config\AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::DIRECT,
    'binding_key' => 'demo.direct',
    'routing_key' => 'demo.direct',
]);

return [
    'demoDirectQueue' => static function () use ($exchangeDemoDirect, $queueDemoDirect, $propertyDemoDirect) {
        /** @var \PhpAmqpLib\Connection\AMQPStreamConnection $connection */
        $connection = Application::getApp()->get('amqpConnection')->getObject();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeDemoDirect;
        $amqpConfig->queueName = $queueDemoDirect;
        // 第三参 true：consumer_tag 追加 _pid_{pid}
        \__APP_NAMESPACE__\Config\AmqpConfig::applyQueueProperty($amqpConfig, $propertyDemoDirect, true);

        return new AmqpDirectQueue($connection, $amqpConfig);
    },
];
