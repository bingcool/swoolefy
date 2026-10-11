<?php

declare(strict_types=1);

namespace Swoolefy\Support\Amqp\Dto;

/**
 * 一条已拉取、尚未确认的 AMQP 消息。
 */
final class ReceivedMessageDto
{
    public function __construct(
        private readonly string $payload,
        private readonly string $exchange,
        private readonly string $routingKey,
        private readonly string $queue,
        private readonly int $deliveryTag,
        private readonly bool $redelivered,
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getDeliveryTag(): int
    {
        return $this->deliveryTag;
    }

    public function isRedelivered(): bool
    {
        return $this->redelivered;
    }
}
