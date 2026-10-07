<?php

/**
 * Order 模块 — Topic 交换机组件（binding_key 支持通配符，如 orderSaveEvent.#）。
 *
 * 消费端：queue 的 binding_key 决定能收到哪些 routing_key 的消息。
 * 发布端：publish 时在消息上指定 routing_key（property 里 routing_key 常留空）。
 *
 * 本文件组件：
 *   orderAddTopicQueue   — 普通 topic 队列
 *   orderDelayTopicQueue — 延迟 topic（AmqpDelayTopicQueue + DLX + TTL）
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpDelayTopicQueue;
use Swoolefy\Library\Amqp\AmqpTopicQueue;
use Test\Config\AmqpConfig;

// --- 交换机 / 队列名 ---
$exchangeOrderTopic = 'order_exchange_topic';
$queueTopicOrderAdd = 'order_add_queue_topic';
$queueTopicOrderAddDelay = 'order_add_queue_topic_delay';

$propertyTopicOrderAdd = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::TOPIC,
    'binding_key' => 'orderSaveEvent.#',
    'routing_key' => '',
    'consumer_tag' => 'consumeFanout1',
]);

$propertyTopicOrderAddDelay = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::TOPIC,
    'binding_key' => 'orderSaveEvent2.#',
    'routing_key' => '',
    'consumer_tag' => 'consumeFanout1',
    'arguments' => [
        'x-dead-letter-exchange' => AmqpConfig::COMMON_DLX_EXCHANGE,
        'x-dead-letter-queue' => $queueTopicOrderAddDelay . '_dlx',
        'x-message-ttl' => 100 * 1000,
    ],
]);

return [
    'orderAddTopicQueue' => static function () use (
        $exchangeOrderTopic,
        $queueTopicOrderAdd,
        $propertyTopicOrderAdd,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderTopic;
        $amqpConfig->queueName = $queueTopicOrderAdd;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyTopicOrderAdd, true);

        return new AmqpTopicQueue($connection, $amqpConfig);
    },

    'orderDelayTopicQueue' => static function () use (
        $exchangeOrderTopic,
        $queueTopicOrderAddDelay,
        $propertyTopicOrderAddDelay,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderTopic;
        $amqpConfig->queueName = $queueTopicOrderAddDelay;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyTopicOrderAddDelay, true);

        return new AmqpDelayTopicQueue($connection, $amqpConfig);
    },
];
