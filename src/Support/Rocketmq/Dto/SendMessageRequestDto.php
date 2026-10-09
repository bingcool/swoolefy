<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class SendMessageRequestDto extends AbstractDto
{
    protected string $topic = '';

    protected string $bodyBase64 = '';

    protected string $tag = '';

    protected string $key = '';

    protected int $delayMs = 0;

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

    public function setDelayMs(int $delayMs): void
    {
        $this->delayMs = $delayMs;
    }

    public function getDelayMs(): int
    {
        return $this->delayMs;
    }
}
