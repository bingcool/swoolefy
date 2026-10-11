<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Fanout 消费队列 2。和 AmqpConsumerFanout 绑在同一个交换机上。
 *
 * bindingKey、routingKey 都留空。Fanout 不按键过滤，所以这个队列会收到和生产队列 1 相同的每一条消息。
 */
class AmqpConsumerFanout1 extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::EXCHANGE_FANOUT;

    protected string $exchangeType = 'fanout';

    protected string $queue = AmqpConst::QUEUE_FANOUT_EXPORT;

    protected string $consumerTag = 'consumeFanout2';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
        ]);
    }
}
