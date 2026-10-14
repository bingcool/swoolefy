<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.changeInvisible 的请求。业务一般不直接构造，用 RocketmqConsumeSession::changeInvisible()。
 */
final class ChangeInvisibleRequestDto extends AbstractDto
{
    /**
     * 拉取这条消息时用的消费组。
     */
    protected string $consumerGroup = '';

    /**
     * 消息所在 Topic。
     */
    protected string $topic = '';

    /**
     * 消息 ID。
     */
    protected string $messageId = '';

    /**
     * 当前回执。调用成功后它就失效了，要换成响应里的新回执。
     */
    protected string $receiptHandle = '';

    /**
     * 从现在起再隐藏多久，10000–300000 毫秒。不是在原不可见时间上累加。
     */
    protected int $invisibleMs = 0;

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
     * 设置当前回执。
     */
    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    /**
     * 读取当前回执。
     */
    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }

    /**
     * 设置新的不可见时长（毫秒），范围由调用方夹取。
     */
    public function setInvisibleMs(int $invisibleMs): void
    {
        $this->invisibleMs = $invisibleMs;
    }

    /**
     * 读取新的不可见时长（毫秒）。
     */
    public function getInvisibleMs(): int
    {
        return $this->invisibleMs;
    }
}
