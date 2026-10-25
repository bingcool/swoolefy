<?php

declare(strict_types=1);

namespace Test\WorkerDaemon\Rocketmq;

use Swoolefy\Support\Rocketmq\Dto\ReceivedMessageDto;
use Swoolefy\Support\Rocketmq\RocketmqConsumerWorkerProcessHandler;

class ConsumerRocketmq extends RocketmqConsumerWorkerProcessHandler
{
    protected string $topic = 'test-demo';

    protected string $consumerGroup = 'test-demo-group';

    protected array $consumerTag = ['demo'];

    protected int $maxNum = 8;

    public function onMessage(ReceivedMessageDto $message): void
    {
        $payload = base64_decode($message->getBodyBase64(), true);
        var_dump(
            'worker rocketmq consume messageId=' . $message->getMessageId()
            . ' topic=' . $message->getTopic()
            . ' tag=' . $message->getTag()
            . ' keys=' . implode('|', $message->getKeys())
            . ' source=' . ($message->getProperty('source') ?? '')
            . ' attempt=' . $message->getDeliveryAttempt()
            . ' body=' . (is_string($payload) ? $payload : '')
        );
    }
}
