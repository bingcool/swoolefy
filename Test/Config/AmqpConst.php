<?php
namespace Test\Config;

use PhpAmqpLib\Exchange\AMQPExchangeType;

class AmqpConst {
    // direct exchange
    const AMQP_EXCHANGE_DIRECT_ORDER = 'order_exchange_direct';

    // 定义交换机下的队列
    const AMQP_QUEUE_DIRECT_ORDER_ADD = 'order_add_queue_direct';
    const AMQP_QUEUE_DIRECT_ORDER_EXPORT = 'order_export_queue_direct';

    const AMQP_DIRECT = [
        // 定义exchange_name
        AmqpConst::AMQP_EXCHANGE_DIRECT_ORDER => [
            // 定义queue 队列名称
            AmqpConst::AMQP_QUEUE_DIRECT_ORDER_ADD => [
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer' // 消费标志
            ],
            // 定义queue 队列名称
            AmqpConst::AMQP_QUEUE_DIRECT_ORDER_EXPORT => [
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer' // 消费标志
            ]
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
        AmqpConst::AMQP_EXCHANGE_FANOUT_ORDER => [
            // 定义queue 队列名称
            AmqpConst::AMQP_QUEUE_FANOUT_ORDER_ADD => [
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
            AmqpConst::AMQP_QUEUE_FANOUT_ORDER_EXPORT => [
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

    const AMQP_TOPIC = [
        // 定义exchange_name
        AmqpConst::AMQP_EXCHANGE_TOPIC_ORDER => [
            // 定义queue 队列名称
            AmqpConst::AMQP_QUEUE_TOPIC_ORDER_ADD => [
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
            AmqpConst::AMQP_QUEUE_TOPIC_ORDER_EXPORT => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent1.#', // binding key
                'routing_key' => '', //路由key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout2' // 消费标志
            ]
        ]
    ];

}