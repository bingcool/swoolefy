<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp;

/**
 * 一次声明用的交换机、队列和绑定。连接参数不在这里。
 */
final class AmqpTopology
{
    /**
     * @param array<string, mixed> $exchangeArguments
     * @param array<string, mixed> $queueArguments
     */
    public function __construct(
        public readonly string $exchange,
        public readonly string $exchangeType = 'direct',
        public readonly string $queue = '',
        public readonly string $bindingKey = '',
        public readonly string $routingKey = '',
        public readonly array $exchangeArguments = [],
        public readonly array $queueArguments = [],
        public readonly string $consumerTag = '',
        public readonly string $deadLetterExchangeType = '',
    ) {
    }
}
