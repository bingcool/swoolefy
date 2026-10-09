<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

final class SendMessageResponseDto extends AbstractDto
{
    protected string $messageId = '';

    public function setMessageId(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
