<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Topic 死信消费。收的是已经在延迟队列里等满 10 秒的消息。
 *
 * 消费侧只写 bindingKey，不写 routingKey：
 * - bindingKey = order.dlx.topic.#：死信队列的匹配模式。
 *   它匹配到期时的 x-dead-letter-routing-key（order.dlx.topic.demo），
 *   不匹配生产时打在延迟消息上的 order.delay.topic.demo。
 * - routingKey 留空。消费进程不发消息；bindingKey 已经有值，也不需要它来顶替绑定键。
 *
 * onMessage() 里 getRoutingKey() 是这条死信实际带的键，即 order.dlx.topic.demo。
 */
class AmqpConsumerDlxTopic extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::DLX_EXCHANGE_TOPIC;

    protected string $exchangeType = 'topic';

    protected string $queue = AmqpConst::DLX_QUEUE_TOPIC;

    /** 死信队列的匹配模式。# 能匹配 order.dlx.topic.demo，匹配不到 order.delay.topic.demo。 */
    protected string $bindingKey = AmqpConst::DLX_BINDING_TOPIC;

    protected string $consumerTag = 'dlxTopic';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'mode' => 'topic',
            'queue' => $message->getQueue(),
            'routingKey' => $message->getRoutingKey(),
            'payload' => $message->getPayload(),
            'receivedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
