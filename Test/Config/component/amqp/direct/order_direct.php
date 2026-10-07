<?php

/**
 * Order 模块 — Direct 交换机组件（发布与消费同一 AmqpDirectQueue 类，按组件区分用途）。
 *
 * Direct 模式：binding_key 与 routing_key 必须一致且唯一，用于精准投递。
 *
 * 本文件组件：
 *   orderAddDirectQueue      — 下单队列（带 ack 示例）
 *   orderExportDirectQueue   — 导出队列（独立 consumer_tag）
 *   orderDelayDirectQueue    — 延迟队列（AmqpDelayDirectQueue + DLX）
 *
 * 新增队列：复制一段 return 闭包，改 exchange/queue/$property 与组件名即可。
 */

declare(strict_types=1);

use PhpAmqpLib\Exchange\AMQPExchangeType;
use Swoolefy\Library\Amqp\AmqpConfig as LibraryAmqpConfig;
use Swoolefy\Library\Amqp\AmqpDelayDirectQueue;
use Swoolefy\Library\Amqp\AmqpDirectQueue;
use Test\Config\AmqpConfig;

// --- 交换机 / 队列名（仅本文件使用，reload 安全） ---
$exchangeOrderDirect = 'order_exchange_direct';
$queueOrderAdd = 'order_add_queue_direct';
$queueOrderExport = 'order_export_queue_direct';
$queueOrderAddDelay = 'order_add_queue_delay_direct';

$propertyOrderAdd = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::DIRECT,
    'binding_key' => 'order-direct-add',
    'routing_key' => 'order-direct-add',
    'arguments' => ['x-max-priority' => 10],
]);

$propertyOrderExport = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::DIRECT,
    'binding_key' => 'order_export',
    'routing_key' => 'order_export',
]);

$propertyOrderAddDelay = AmqpConfig::queueProperty([
    'type' => AMQPExchangeType::DIRECT,
    'binding_key' => 'order_delay',
    'routing_key' => 'order_delay',
    'arguments' => [
        'x-dead-letter-exchange' => AmqpConfig::COMMON_DLX_EXCHANGE,
        'x-dead-letter-queue' => $queueOrderAddDelay . '_dlx',
        'x-message-ttl' => 3 * 1000,
        'x-max-priority' => 10,
    ],
]);

return [
    'orderAddDirectQueue' => static function () use (
        $exchangeOrderDirect,
        $queueOrderAdd,
        $propertyOrderAdd,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderDirect;
        $amqpConfig->queueName = $queueOrderAdd;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyOrderAdd);

        $amqpDirect = new AmqpDirectQueue($connection, $amqpConfig);
        $amqpDirect->setAckHandler(static function (\PhpAmqpLib\Message\AMQPMessage $message): void {
            echo 'Message acked with content ' . $message->body . PHP_EOL;
        });

        return $amqpDirect;
    },

    'orderExportDirectQueue' => static function () use (
        $exchangeOrderDirect,
        $queueOrderExport,
        $propertyOrderExport,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderDirect;
        $amqpConfig->queueName = $queueOrderExport;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyOrderExport, true);

        return new AmqpDirectQueue($connection, $amqpConfig);
    },

    'orderDelayDirectQueue' => static function () use (
        $exchangeOrderDirect,
        $queueOrderAddDelay,
        $propertyOrderAddDelay,
    ) {
        $connection = \Test\App::getAmqpConnection();
        $amqpConfig = new LibraryAmqpConfig();
        $amqpConfig->exchangeName = $exchangeOrderDirect;
        $amqpConfig->queueName = $queueOrderAddDelay;
        AmqpConfig::applyQueueProperty($amqpConfig, $propertyOrderAddDelay, true);

        return new AmqpDelayDirectQueue($connection, $amqpConfig);
    },
];
