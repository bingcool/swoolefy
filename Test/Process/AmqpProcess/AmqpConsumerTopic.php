<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Topic 消费。收 AmqpPublishTopic 直接发出的消息。
 *
 * 只写 bindingKey = orderSaveEvent.#，不写 routingKey。
 * 消费进程不发消息。这个模式能匹配 orderSaveEvent.send，也能匹配 orderSaveEvent 开头的其他键。
 *
 * onMessage() 里 getRoutingKey() 是这条消息实际带的键，例如 orderSaveEvent.send，不是 bindingKey 那个模式。
 */
class AmqpConsumerTopic extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::EXCHANGE_TOPIC;

    protected string $exchangeType = 'topic';

    protected string $queue = AmqpConst::QUEUE_TOPIC_ADD;

    /** 队列匹配模式。Topic 消费用它决定收哪些 routingKey，自己不设置消息键。 */
    protected string $bindingKey = AmqpConst::BINDING_TOPIC_ADD;

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'routingKey' => $message->getRoutingKey(),
            'payload' => $message->getPayload(),
        ]);
    }
}
