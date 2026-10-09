<?php

declare(strict_types=1);

namespace Swoolefy\Core\Sidecar\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class SidecarResponseDto extends AbstractDto
{
    public const CODE_OK = 'OK';

    public const CODE_INVALID_ARGUMENT = 'INVALID_ARGUMENT';

    public const CODE_METHOD_NOT_FOUND = 'METHOD_NOT_FOUND';

    public const CODE_UNAVAILABLE = 'UNAVAILABLE';

    public const CODE_OVERLOADED = 'OVERLOADED';

    public const CODE_TIMEOUT = 'TIMEOUT';

    public const CODE_INTERNAL = 'INTERNAL';

    public const CODE_OUTCOME_UNKNOWN = 'OUTCOME_UNKNOWN';

    /** @var list<string> */
    public const CODES = [
        self::CODE_OK,
        self::CODE_INVALID_ARGUMENT,
        self::CODE_METHOD_NOT_FOUND,
        self::CODE_UNAVAILABLE,
        self::CODE_OVERLOADED,
        self::CODE_TIMEOUT,
        self::CODE_INTERNAL,
        self::CODE_OUTCOME_UNKNOWN,
    ];

    protected string $requestId = '';

    protected string $code = '';

    protected string $message = '';

    /** @var array<string, mixed> */
    protected array $payload = [];

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
