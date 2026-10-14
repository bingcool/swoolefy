<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.receive 的响应。
 */
final class ReceiveMessageResponseDto extends AbstractDto
{
    /**
     * 本次拉到的消息，0 到 maxNum 条。每项是原始数组，用 ReceivedMessageDto::fromArray() 还原。
     *
     * @var list<array<string, mixed>>
     */
    protected array $messages = [];

    /**
     * 设置消息列表。
     *
     * @param list<array<string, mixed>> $messages
     */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    /**
     * 读取消息列表。没有消息时为空数组。
     *
     * @return list<array<string, mixed>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
