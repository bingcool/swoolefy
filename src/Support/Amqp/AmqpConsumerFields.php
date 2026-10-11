<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

/**
 * 从消费进程的字段组装声明参数。字段定义在各自的 handler 上。
 */
trait AmqpConsumerFields
{
    protected function amqpTopology(): AmqpTopology
    {
        return new AmqpTopology(
            exchange: $this->exchange,
            exchangeType: $this->exchangeType,
            queue: $this->queue,
            bindingKey: $this->bindingKey !== '' ? $this->bindingKey : $this->routingKey,
            routingKey: $this->routingKey,
            exchangeArguments: $this->exchangeArguments,
            queueArguments: $this->queueArguments,
            consumerTag: $this->consumerTag,
        );
    }
}
