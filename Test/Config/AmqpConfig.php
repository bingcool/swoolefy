<?php
namespace Test\Config;

use PhpAmqpLib\Exchange\AMQPExchangeType;

class AmqpConfig {
    // direct exchange
    const AMQP_EXCHANGE_DIRECT_ORDER = 'order_exchange_direct';

    // 定义交换机下的队列
    const AMQP_QUEUE_DIRECT_ORDER_ADD = 'order_add_queue_direct';
    const AMQP_QUEUE_DIRECT_ORDER_EXPORT = 'order_export_queue_direct';
    const AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY = 'order_add_queue_delay_direct';

    const AMQP_DIRECT = [
        // 定义exchange_name
        AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER => [
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD => [
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer', // 消费标志
            ],
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_EXPORT => [
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer' // 消费标志
            ],

            // 死信队列实现延迟队列
            AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY => [
                // 定义死信队列
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer', // 消费标志
                'arguments' => [
                    // 延迟队列
                    'x-dead-letter-exchange' => AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER, //在同一个交换机下，这个不要改变
                    'x-dead-letter-queue'    => AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY.'_dead', // 延迟队列名称，一定时间没有被消费，消息江北转发到此队列
                    'x-dead-letter-routing-key' => AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY.'_dead', // 队列binding key
                    'x-message-ttl' => 3 * 1000
                ]
            ],
        ]
    ];

    // fanout
    // order direct exchange
    const AMQP_EXCHANGE_FANOUT_ORDER = 'order_exchange_fanout';

    // 定义交换机下的队列
    const AMQP_QUEUE_FANOUT_ORDER_ADD = 'order_add_queue_fanout';
    const AMQP_QUEUE_FANOUT_ORDER_EXPORT = 'order_export_queue_fanout';

    const AMQP_FANOUT = [
        // 定义exchange_name
        AmqpConfig::AMQP_EXCHANGE_FANOUT_ORDER => [
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_ADD => [
                'type' => AMQPExchangeType::FANOUT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1' // 消费标志
            ],
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_EXPORT => [
                'type' => AMQPExchangeType::FANOUT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout2' // 消费标志
            ]
        ]
    ];



    // topic exchange
    // order direct exchange
    const AMQP_EXCHANGE_TOPIC_ORDER = 'order_exchange_topic';

    // 定义交换机下的队列
    const AMQP_QUEUE_TOPIC_ORDER_ADD = 'order_add_queue_topic';
    const AMQP_QUEUE_TOPIC_ORDER_EXPORT = 'order_export_queue_topic';
    const AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY = 'order_add_queue_topic_delay'; // 延迟死信队列

    const AMQP_TOPIC = [
        // 定义exchange_name
        AmqpConfig::AMQP_EXCHANGE_TOPIC_ORDER => [
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent.#', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1' // 消费标志
            ],

            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_EXPORT => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent1.#', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout2' // 消费标志
            ],

            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent2.#', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1', // 消费标志
                'arguments' => [
                    // 定义延迟队列
                    'x-dead-letter-exchange' => AmqpConfig::AMQP_EXCHANGE_TOPIC_ORDER, //在同一个交换机下，这个不要改变
                    'x-dead-letter-queue'    => AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY.'_dead', // 延迟队列名称，一定时间没有被消费，消息江北转发到此队列
                    'x-dead-letter-routing-key' => 'orderSaveEvent2-all', // 队列binding key
                    'x-message-ttl' => 3 * 1000
                ]
            ],
        ]
    ];

}