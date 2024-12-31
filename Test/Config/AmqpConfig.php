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
                'binding_key' => 'order-direct-add', // DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'routing_key' => 'order-direct-add', // DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer', // 消费标志
                'arguments' => [
                    // 设置优先最大值为10，投递的message的priority值越大，优先级越高
                    'x-max-priority' => 10
                ]
            ],
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_EXPORT => [
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', //binding_key.DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'routing_key' => '', //路由key. DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer', // 唯一消费者标志
            ],

            // 死信队列实现延迟队列
            AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY => [
                // 定义死信队列
                'type' => AMQPExchangeType::DIRECT,
                'binding_key' => '', // binding key.DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'routing_key' => '', //路由key.DIRECT 模式下 binding key 与routing_key要一致，并且唯一，精准投递
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumer', // 消费标志
                'arguments' => [
                    // 延迟队列
                    'x-dead-letter-exchange' => AmqpConfig::AMQP_EXCHANGE_DIRECT_ORDER, //在同一个交换机下，这个不要改变
                    'x-dead-letter-queue'    => AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY.'_dead', // 延迟队列名称，一定时间没有被消费，消息将转发到此队列
                    'x-dead-letter-routing-key' => AmqpConfig::AMQP_QUEUE_DIRECT_ORDER_ADD_DELAY.'_dead', // // 队列routing-key与binding key.这里默认创一个direct模式的死信队列。死信队列的routing-key与binding key一致
                    'x-message-ttl' => 3 * 1000,
                    // 延迟队列也可以设置优先级，设置优先最大值为10，投递的message的priority值越大，优先级越高
                    'x-max-priority' => 10
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

    /**
     * 在RabbitMQ中，fanout 交换器是一种广播交换器，它将收到的消息分发到所有与之绑定的队列，
     * 而不考虑路由键（routing key）这种模式非常适合需要将同一条消息发送给多个消费者的场景，
     * 例如发布/订阅系统中的广播消息
     * 特性
     * 广播消息：消息会被分发到所有绑定到该交换器的队列。
     * 忽略路由键：在绑定队列时和发送消息时，路由键都会被忽略。
     * 简单易用：非常适合用来实现日志广播、事件通知等场景。
     */
    const AMQP_FANOUT = [
        // 定义exchange_name
        AmqpConfig::AMQP_EXCHANGE_FANOUT_ORDER => [
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_ADD => [
                'type' => AMQPExchangeType::FANOUT,
                'binding_key' => '', // binding key.不考虑绑定键,为空即可
                'routing_key' => '', //路由key.不考虑路由键,为空即可
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1' // 消费标志
            ],
            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_FANOUT_ORDER_EXPORT => [
                'type' => AMQPExchangeType::FANOUT,
                'binding_key' => '', // binding key.不考虑绑定键,为空即可
                'routing_key' => '', //路由key.不考虑路由键,为空即可
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
                'binding_key' => 'orderSaveEvent.#', // binding key.在 topic 交换器中，binding key 可以包含通配符进行模式匹配，或者不包含通配符，类似direct模式
                'routing_key' => '', //路由key.这里为空,publish时指定routing_key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1' // 消费标志
            ],

            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_EXPORT => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent1.#', // binding key.在 topic 交换器中，binding key 可以包含通配符进行模式匹配，或者不包含通配符，类似direct模式
                'routing_key' => '', //路由key.这里为空,publish时指定routing_key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout2' // 消费标志
            ],

            // 定义queue 队列名称
            AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY => [
                'type' => AMQPExchangeType::TOPIC,
                'binding_key' => 'orderSaveEvent2.#', // binding key.在 topic 交换器中，binding key 可以包含通配符进行模式匹配，或者不包含通配符，类似direct模式
                'routing_key' => '', //路由key.这里为空,publish时指定routing_key
                'passive' => false, //是否检测同名队列
                'durable' => true, //是否开启队列持久化
                'exclusive' => false, //队列是否可以被其他队列访问
                'auto_delete' => false, //通道关闭后是否删除队列
                'consumer_tag' => 'consumeFanout1', // 消费标志
                'arguments' => [
                    // 定义延迟队列
                    'x-dead-letter-exchange' => AmqpConfig::AMQP_EXCHANGE_TOPIC_ORDER, //在同一个交换机下，这个不要改变
                    'x-dead-letter-queue'    => AmqpConfig::AMQP_QUEUE_TOPIC_ORDER_ADD_DELAY.'_dead', // 延迟队列名称，一定时间没有被消费，消息将转发到此队列
                    'x-dead-letter-routing-key' => 'orderSaveEvent2-all', // 队列routing-key与binding key.这里默认创一个direct模式的死信队列。死信队列的routing-key与binding key一致.注意这里key不使用通配符,它的行为类似于direct交换器
                    'x-message-ttl' => 100 * 1000
                ]
            ],
        ]
    ];

}