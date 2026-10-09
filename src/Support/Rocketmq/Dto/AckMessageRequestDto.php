<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class AckMessageRequestDto extends AbstractDto
{
    protected string $consumerGroup = '';

    protected string $receiptHandle = '';

    protected string $messageId = '';

    protected string $topic = '';

    public function setConsumerGroup(string $consumerGroup): void
    {
        $this->consumerGroup = $consumerGroup;
    }

    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }

    public function setMessageId(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function setTopic(string $topic): void
    {
        $this->topic = $topic;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }
}
