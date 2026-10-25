<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Direct 消费。收 AmqpPublish 直接发出的消息，不是死信。
 *
 * - bindingKey = order-direct-add：把本队列绑到交换机。只有 routingKey 完全等于它的消息才会进来。
 * - routingKey 写成同一个值，只是在 bindingKey 为空时顶替绑定键。本进程不拿它 publish。
 *
 * onMessage() 里 getRoutingKey() 是这条消息实际带的键，正常就是 order-direct-add。
 * 队列参数要和生产端一致，这里都带 x-max-priority，否则 Broker 会拒绝重复声明。
 */
class AmqpConsumer extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::EXCHANGE_DIRECT;

    protected string $exchangeType = 'direct';

    protected string $queue = AmqpConst::QUEUE_DIRECT_ADD;

    /** 队列绑定规则，要和生产消息的 routingKey 完全一致。 */
    protected string $bindingKey = AmqpConst::ROUTING_DIRECT_ADD;

    /** 不用于发消息。bindingKey 已有值时，它不参与路由。 */
    protected string $routingKey = AmqpConst::ROUTING_DIRECT_ADD;

    protected array $queueArguments = ['x-max-priority' => 10];

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'queue' => $message->getQueue(),
            'routingKey' => $message->getRoutingKey(),
            'payload' => $message->getPayload(),
            'redelivered' => $message->isRedelivered(),
        ]);
    }
}
