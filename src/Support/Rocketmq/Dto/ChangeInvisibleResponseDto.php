<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.changeInvisible 的响应。
 */
final class ChangeInvisibleResponseDto extends AbstractDto
{
    /**
     * proxy 新签发的回执，后续 ack 或再次 changeInvisible 必须用它。
     */
    protected string $receiptHandle = '';

    /**
     * 设置新回执。
     */
    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    /**
     * 读取新回执。
     */
    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }
}
