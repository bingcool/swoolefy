<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class ReceiveMessageRequestDto extends AbstractDto
{
    protected string $topic = '';

    protected string $consumerGroup = '';

    protected string $consumerTag = '*';

    protected int $maxNum = 2;

    protected int $invisibleMs = 20000;

    public function setTopic(string $topic): void
    {
        $this->topic = $topic;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setConsumerGroup(string $consumerGroup): void
    {
        $this->consumerGroup = $consumerGroup;
    }

    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    public function setConsumerTag(string $consumerTag): void
    {
        $this->consumerTag = $consumerTag;
    }

    public function getConsumerTag(): string
    {
        return $this->consumerTag;
    }

    public function setMaxNum(int $maxNum): void
    {
        $this->maxNum = $maxNum;
    }

    public function getMaxNum(): int
    {
        return $this->maxNum;
    }

    public function setInvisibleMs(int $invisibleMs): void
    {
        $this->invisibleMs = $invisibleMs;
    }

    public function getInvisibleMs(): int
    {
        return $this->invisibleMs;
    }
}
