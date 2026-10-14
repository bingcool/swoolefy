<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.ack 的请求。业务一般不直接构造，用 RocketmqConsumeSession::ack()。
 */
final class AckMessageRequestDto extends AbstractDto
{
    /**
     * 拉取这条消息时用的消费组。sidecar 按它找到对应的 SimpleConsumer。
     */
    protected string $consumerGroup = '';

    /**
     * 确认回执，取自 ReceivedMessageDto::getReceiptHandle()。改过不可见时间后必须用新回执。
     */
    protected string $receiptHandle = '';

    /**
     * 消息 ID。
     */
    protected string $messageId = '';

    /**
     * 消息所在 Topic。
     */
    protected string $topic = '';

    /**
     * 设置消费组。
     */
    public function setConsumerGroup(string $consumerGroup): void
    {
        $this->consumerGroup = $consumerGroup;
    }

    /**
     * 读取消费组。
     */
    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    /**
     * 设置确认回执。
     */
    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    /**
     * 读取确认回执。
     */
    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }

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

    /**
     * 设置 Topic。
     */
    public function setTopic(string $topic): void
    {
        $this->topic = $topic;
    }

    /**
     * 读取 Topic。
     */
    public function getTopic(): string
    {
        return $this->topic;
    }
}
