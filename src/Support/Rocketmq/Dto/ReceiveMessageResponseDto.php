<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class ReceiveMessageResponseDto extends AbstractDto
{
    /** @var list<array<string, mixed>> */
    protected array $messages = [];

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
