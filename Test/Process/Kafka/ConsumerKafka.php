<?php

declare(strict_types=1);

namespace Test\Process\Kafka;

use Swoolefy\Support\Kafka\Dto\ReceivedMessageDto;
use Swoolefy\Support\Kafka\KafkaConsumerProcessHandler;

class ConsumerKafka extends KafkaConsumerProcessHandler
{
    protected string $topic = 'topicOrder1';

    protected string $consumerGroup = 'order_group1';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump(
            'topic=' . $message->getTopic()
            . ' partition=' . $message->getPartition()
            . ' offset=' . $message->getOffset()
            . ' key=' . $message->getKey()
            . ' payload=' . $message->getPayload()
        );
    }
}
