<?php

/**
 * Order 模块 — Fanout 交换机组件（广播：消息进 exchange 后复制到所有绑定队列）。
 *
 * binding_key / routing_key 留空；发布端可只绑 exchange，消费端各绑独立 queue。
 *
 * 本文件组件：
 *   amqpOrderFanoutPublish    — 仅 exchange，用于 publish 广播
 *   amqpOrderAddFanoutQueue   — 消费队列 1
 *   amqpExportFanoutQueue     — 消费队列 2
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpFanoutQueue;
use Test\Config\AmqpConfig;

// --- 交换机 / 队列名 ---
$exchangeOrderFanout = 'order_exchange_fanout';
$queueFanoutOrderAdd = 'order_add_queue_fanout';
$queueFanoutOrderExport = 'order_export_queue_fanout';

$propertyFanoutBase = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::FANOUT,
    'binding_key' => '',
    'routing_key' => '',
]);

$propertyFanoutAdd = AmqpConfig::queueProperty(array_merge($propertyFanoutBase, [
    'consumer_tag' => 'consumeFanout1',
]));

$propertyFanoutExport = AmqpConfig::queueProperty(array_merge($propertyFanoutBase, [
    'consumer_tag' => 'consumeFanout2',
]));

return [
    'amqpOrderFanoutPublish' => static function () use ($exchangeOrderFanout) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderFanout;
        $amqpFanout = new AmqpFanoutQueue($connection, $amqpConfig);
        $amqpFanout->setAckHandler(static function (\PhpAmqpLib\Message\AMQPMessage $message): void {
            echo 'Message acked with content ' . $message->body . PHP_EOL;
        });

        return $amqpFanout;
    },

    'amqpOrderAddFanoutQueue' => static function () use (
        $exchangeOrderFanout,
        $queueFanoutOrderAdd,
        $propertyFanoutAdd,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderFanout;
        $amqpConfig->queueName = $queueFanoutOrderAdd;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyFanoutAdd, true);

        return new AmqpFanoutQueue($connection, $amqpConfig);
    },

    'amqpExportFanoutQueue' => static function () use (
        $exchangeOrderFanout,
        $queueFanoutOrderExport,
        $propertyFanoutExport,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderFanout;
        $amqpConfig->queueName = $queueFanoutOrderExport;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyFanoutExport, true);

        return new AmqpFanoutQueue($connection, $amqpConfig);
    },
];
