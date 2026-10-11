<?php

declare(strict_types=1);

namespace Swoolefy\Support\Kafka\Dto;

/**
 * 一条已经从 Kafka 拉到的消息。err 为 NO_ERROR 时才会交给 onMessage。
 */
final class ReceivedMessageDto
{
    public function __construct(
        private readonly string $payload,
        private readonly string $key,
        private readonly string $topic,
        private readonly int $partition,
        private readonly int $offset,
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getPartition(): int
    {
        return $this->partition;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
