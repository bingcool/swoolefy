<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class ReceivedMessageDto extends AbstractDto
{
    protected string $messageId = '';

    protected string $topic = '';

    protected string $bodyBase64 = '';

    protected string $tag = '';

    protected string $key = '';

    protected string $receiptHandle = '';

    protected int $deliveryAttempt = 0;

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

    public function setBodyBase64(string $bodyBase64): void
    {
        $this->bodyBase64 = $bodyBase64;
    }

    public function getBodyBase64(): string
    {
        return $this->bodyBase64;
    }

    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }

    public function setDeliveryAttempt(int $deliveryAttempt): void
    {
        $this->deliveryAttempt = $deliveryAttempt;
    }

    public function getDeliveryAttempt(): int
    {
        return $this->deliveryAttempt;
    }
}
