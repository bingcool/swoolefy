<?php
namespace Test\Process\AmqpProcess;

class AmqpConst {
    //1.queue ：存储消息的队列，可以指定name来唯一确定

    //2.exchange：交换机（常用有三种），用于接收生产者发来的消息，并通过binding-key 与 routing-key 的匹配关系来决定将消息分发到指定queue

    //2.1 Direct（路由模式）：完全匹配 > 当消息的routing-key 与 exchange和queue间的binding-key完全匹配时，将消息分发到该queue

    //2.2 Fanout （订阅模式）：与binding-key和routing-key无关，将接受到的消息分发给有绑定关系的所有队列（不论binding-key和routing-key是什么）

    //2.3 Topic （通配符模式）：用消息的routing-key 与 exchange和queue间的binding-key 进行模式匹配，当满足规则时，分发到满足规则的所有队列

    const AMQP_HOST = '172.17.0.1';
    const AMQP_PORT = 5672;
    const AMQP_USER = 'admin';
    const AMQP_PASS = 'admin';
    const AMQP_VHOST = 'my_vhost';


    const AMQP_EXCHANGE_ROUTER = 'router';
    const AMQP_QUEUE = 'queue';
    const AMQP_CONSUMER_TAG = 'consumer';

    const AMQP_EXCHANGE_ROUTER_TOPIC = 'router-topic';
    const AMQP_QUEUE_TOPIC = 'amqp_queue_topic_order';
    const AMQP_QUEUE_TOPIC1 = 'amqp_queue_topic_order1';
    const AMQP_QUEUE_TOPIC_ROUTING_KEY1 = 'orderSaveEvent.#';


    const AMQP_EXCHANGE_ROUTER_FANOUT = 'router-fanout';

    // fanout 队列1
    const AMQP_QUEUE_FANOUT = 'amqp_queue_fanout_1';
    // fanout 队列2
    const AMQP_QUEUE_FANOUT1 = 'amqp_queue_fanout_2';

}

