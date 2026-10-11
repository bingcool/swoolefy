<?php

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Fanout 死信消费。收的是已经在延迟队列里等满 10 秒的消息。
 *
 * 消费侧：
 * - bindingKey、routingKey 都留空。Fanout 只要队列绑在死信交换机上，就会收到交换机上的每一条消息。
 * - 本进程不发消息，空的 routingKey 也不用来 publish。
 */
class AmqpConsumerDlxFanout extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::DLX_EXCHANGE_FANOUT;

    protected string $exchangeType = 'fanout';

    protected string $queue = AmqpConst::DLX_QUEUE_FANOUT;

    protected string $consumerTag = 'dlxFanout';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'mode' => 'fanout',
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
            'receivedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
