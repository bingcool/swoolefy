<?php

declare(strict_types=1);

namespace Test\Process\AmqpProcess;

use Swoolefy\Support\Amqp\AmqpConsumerProcessHandler;
use Swoolefy\Support\Amqp\Dto\ReceivedMessageDto;

/**
 * Fanout 消费队列 1。收 AmqpPublishFanout 直接广播的消息。
 *
 * bindingKey、routingKey 都留空。Fanout 只要本队列绑在 EXCHANGE_FANOUT 上，就会收到每一条消息。
 * AmqpConsumerFanout1 是同一交换机上的另一个队列，收到的是同一批消息，不是竞争分摊。
 */
class AmqpConsumerFanout extends AmqpConsumerProcessHandler
{
    protected string $exchange = AmqpConst::EXCHANGE_FANOUT;

    protected string $exchangeType = 'fanout';

    protected string $queue = AmqpConst::QUEUE_FANOUT_ADD;

    protected string $consumerTag = 'consumeFanout1';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
        ]);
    }
}
