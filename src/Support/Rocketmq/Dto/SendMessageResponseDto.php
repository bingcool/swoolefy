<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.producer.send 的响应。
 */
final class SendMessageResponseDto extends AbstractDto
{
    /**
     * broker 分配的消息 ID，可以在控制台按它查消息。
     */
    protected string $messageId = '';

    /**
     * 设置消息 ID。
     */
    public function setMessageId(string $messageId): void
    {
        $this->messageId = $messageId;
    }

    /**
     * 读取消息 ID。
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }
}
