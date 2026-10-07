<?php

/**
 * 示例 Topic 交换机（binding_key 支持通配符，如 demo.event.#）。
 *
 * 消费端：队列的 binding_key 决定能收到哪些 routing_key。
 *   * 匹配一个词，# 匹配零个或多个词（以 . 分隔）。
 * 发布端：publish 时在消息上指定 routing_key；property 里的 routing_key 常留空。
 *
 * 本文件组件：
 *   demoTopicQueue — binding_key 为 demo.event.#，可收到 demo.event、demo.event.created 等
 *
 * 延迟队列可改用 AmqpDelayTopicQueue，并在 arguments 中设置
 * x-dead-letter-exchange（可引用 Config\AmqpConfig::COMMON_DLX_EXCHANGE）、
 * x-dead-letter-queue、x-message-ttl。
 *
 * exchange、queue 用局部变量，勿用全局 const，否则 reloadGlobalConf 重复 include 会报错。
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Core\Application;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpTopicQueue;

/** 交换机名（仅本文件） */
$exchangeDemoTopic = 'demo_exchange_topic';

/** 队列名（仅本文件） */
$queueDemoTopic = 'demo_queue_topic';

$propertyDemoTopic = \__APP_NAMESPACE__\Config\AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::TOPIC,
    'binding_key' => 'demo.event.#',
    'routing_key' => '',
    'consumer_tag' => 'demoTopicConsumer',
]);

return [
    'demoTopicQueue' => static function () use ($exchangeDemoTopic, $queueDemoTopic, $propertyDemoTopic) {
        $connection = Application::getApp()->get('amqpConnection')->getObject();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeDemoTopic;
        $amqpConfig->queueName = $queueDemoTopic;
        \__APP_NAMESPACE__\Config\AmqpConfig::applyQueueProperty($amqpConfig, $propertyDemoTopic, true);

        return new AmqpTopicQueue($connection, $amqpConfig);
    },
];
