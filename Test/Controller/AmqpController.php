<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Support\Amqp\AmqpProducer;
use Test\Config\AmqpConfig;
use Test\Process\AmqpProcess\AmqpConst;

class AmqpController extends BController
{
    /**
     * 测试 AMQP Direct 队列发布消息。
     *
     * Route: GET /api/amqp/publish
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 AMQP Direct 队列发布消息')]
    public function testPublish(): bool
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_DIRECT,
            exchangeType: 'direct',
            queue: AmqpConst::QUEUE_DIRECT_ADD,
            bindingKey: AmqpConst::ROUTING_DIRECT_ADD,
            routingKey: AmqpConst::ROUTING_DIRECT_ADD,
            queueArguments: ['x-max-priority' => 10],
        );
        $producer->publish('amqp direct -' . time());

        return true;
    }

    /**
     * 测试 AMQP Topic 队列发布消息。
     *
     * Route: GET /api/amqp/publish-delay-topic
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-topic' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 AMQP Topic 队列发布消息')]
    public function testPublish1(): bool
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_TOPIC,
            exchangeType: 'topic',
            queue: AmqpConst::QUEUE_TOPIC_ADD,
            bindingKey: AmqpConst::BINDING_TOPIC_ADD,
            routingKey: AmqpConst::ROUTING_TOPIC_SEND,
        );
        $producer->publish('amqp topic -' . date('Y-m-d H:i:s'), AmqpConst::ROUTING_TOPIC_SEND);

        return true;
    }

    /**
     * 测试 AMQP 延迟 Direct 队列发布消息。
     *
     * Route: GET /api/amqp/publish-delay-direct
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/amqp/publish-delay-direct' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 AMQP 延迟 Direct 队列发布消息')]
    public function testPublish2(): bool
    {
        $producer = new AmqpProducer(
            exchange: AmqpConst::EXCHANGE_DIRECT,
            exchangeType: 'direct',
            queue: AmqpConst::QUEUE_DIRECT_DELAY,
            bindingKey: AmqpConst::ROUTING_DIRECT_DELAY,
            routingKey: AmqpConst::ROUTING_DIRECT_DELAY,
            queueArguments: [
                'x-dead-letter-exchange' => AmqpConfig::COMMON_DLX_EXCHANGE,
                'x-dead-letter-routing-key' => AmqpConst::ROUTING_DIRECT_ADD,
                'x-message-ttl' => 3 * 1000,
                'x-max-priority' => 10,
            ],
        );
        $producer->publish('amqp delay direct -' . date('Y-m-d H:i:s'));

        return true;
    }
}
