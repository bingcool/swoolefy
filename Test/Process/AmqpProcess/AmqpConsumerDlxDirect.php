<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Direct 死信消费。收的是已经在延迟队列里等满 10 秒的消息。
 *
 * 消费侧不发消息：
 * - bindingKey = order.dlx.direct：把死信队列绑到死信交换机。
 *   它匹配的是到期时的 x-dead-letter-routing-key，不是生产时打在延迟消息上的 order.delay.direct。
 * - routingKey 与 bindingKey 写成同一个值。Direct 消费只是用它兜底绑定；本进程不会拿它去 publish。
 *
 * onMessage() 里 getRoutingKey() 返回的是这条死信实际带的键，也就是 order.dlx.direct。
 */
class AmqpConsumerDlxDirect extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::DLX_EXCHANGE_DIRECT;

    protected string $exchangeType = 'direct';

    protected string $queue = AmqpConst::DLX_QUEUE_DIRECT;

    /** 死信队列的绑定规则，要和延迟队列的 x-dead-letter-routing-key 完全一致。 */
    protected string $bindingKey = AmqpConst::DLX_ROUTING_DIRECT;

    /** bindingKey 已写时，这个字段不参与发消息，只在 bindingKey 为空时顶替绑定键。 */
    protected string $routingKey = AmqpConst::DLX_ROUTING_DIRECT;

    protected string $consumerTag = 'dlxDirect';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'mode' => 'direct',
            'queue' => $message->getQueue(),
            'routingKey' => $message->getRoutingKey(),
            'payload' => $message->getPayload(),
            'receivedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
