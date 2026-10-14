<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.receive 返回的一条消息。
 */
final class ReceivedMessageDto extends AbstractDto
{
    /**
     * 消息 ID。同一条消息重投时不变，业务按它做幂等。
     */
    protected string $messageId = '';

    /**
     * 消息所在 Topic。
     */
    protected string $topic = '';

    /**
     * 消息体的 base64。
     */
    protected string $bodyBase64 = '';

    /**
     * 这条消息自己的 Tag（生产端设置的值），消费端用 $consumerTag 订阅对应 Tag。
     */
    protected string $tag = '';

    /**
     * 生产端写入的业务 Key，原样返回（Key 本身含逗号也不会被拆开）。
     *
     * @var list<string>
     */
    protected array $keys = [];

    /**
     * 生产端写入的自定义属性，已去掉 RocketMQ 系统属性。
     *
     * @var array<string, string>
     */
    protected array $properties = [];

    /**
     * 顺序消息分组，普通消息为空串。
     */
    protected string $messageGroup = '';

    /**
     * 生产端发送时刻，Unix 毫秒；取不到时为 0。
     */
    protected int $bornTimestampMs = 0;

    /**
     * 定时消息的计划投递时刻，Unix 毫秒；非定时消息为 0。
     */
    protected int $deliveryTimestampMs = 0;

    /**
     * 确认回执。RocketmqConsumeSession::changeInvisible() 成功后会被替换成新回执，旧回执随即失效。
     */
    protected string $receiptHandle = '';

    /**
     * 第几次投递，首次为 1。
     */
    protected int $deliveryAttempt = 0;

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

    /**
     * 设置消息体的 base64。
     */
    public function setBodyBase64(string $bodyBase64): void
    {
        $this->bodyBase64 = $bodyBase64;
    }

    /**
     * 读取消息体的 base64，原文用 base64_decode($value, true) 取得。
     */
    public function getBodyBase64(): string
    {
        return $this->bodyBase64;
    }

    /**
     * 设置消息 Tag。
     */
    public function setTag(string $tag): void
    {
        $this->tag = $tag;
    }

    /**
     * 读取消息 Tag，没有 Tag 时为空串。
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * 设置业务 Key。
     *
     * @param list<string> $keys
     */
    public function setKeys(array $keys): void
    {
        $this->keys = $keys;
    }

    /**
     * 读取业务 Key，没有时为空数组。
     *
     * @return list<string>
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * 设置自定义属性。
     *
     * @param array<string, string> $properties
     */
    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    /**
     * 读取全部自定义属性，没有时为空数组。
     *
     * @return array<string, string>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * 读取单个自定义属性，不存在时返回 $default。
     */
    public function getProperty(string $key, ?string $default = null): ?string
    {
        return $this->properties[$key] ?? $default;
    }

    /**
     * 设置顺序消息分组。
     */
    public function setMessageGroup(string $messageGroup): void
    {
        $this->messageGroup = $messageGroup;
    }

    /**
     * 读取顺序消息分组，普通消息为空串。
     */
    public function getMessageGroup(): string
    {
        return $this->messageGroup;
    }

    /**
     * 设置生产端发送时刻（Unix 毫秒）。
     */
    public function setBornTimestampMs(int $bornTimestampMs): void
    {
        $this->bornTimestampMs = $bornTimestampMs;
    }

    /**
     * 读取生产端发送时刻（Unix 毫秒），取不到时为 0。可用来算端到端延迟。
     */
    public function getBornTimestampMs(): int
    {
        return $this->bornTimestampMs;
    }

    /**
     * 设置计划投递时刻（Unix 毫秒）。
     */
    public function setDeliveryTimestampMs(int $deliveryTimestampMs): void
    {
        $this->deliveryTimestampMs = $deliveryTimestampMs;
    }

    /**
     * 读取计划投递时刻（Unix 毫秒），非定时消息为 0。
     */
    public function getDeliveryTimestampMs(): int
    {
        return $this->deliveryTimestampMs;
    }

    /**
     * 设置确认回执。changeInvisible() 成功后由 session 调用，换成新回执。
     */
    public function setReceiptHandle(string $receiptHandle): void
    {
        $this->receiptHandle = $receiptHandle;
    }

    /**
     * 读取当前有效的确认回执。
     */
    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }

    /**
     * 设置投递次数。
     */
    public function setDeliveryAttempt(int $deliveryAttempt): void
    {
        $this->deliveryAttempt = $deliveryAttempt;
    }

    /**
     * 读取投递次数，首次为 1，每次重投加 1。
     */
    public function getDeliveryAttempt(): int
    {
        return $this->deliveryAttempt;
    }
}
