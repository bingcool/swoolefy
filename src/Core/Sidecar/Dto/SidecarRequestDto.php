<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class SidecarRequestDto extends AbstractDto
{
    protected string $requestId = '';

    protected string $method = '';

    /** @var array<string, mixed> */
    protected array $payload = [];

    protected int $timeoutMs = 0;

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setTimeoutMs(int $timeoutMs): void
    {
        $this->timeoutMs = $timeoutMs;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }
}
