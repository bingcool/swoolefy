<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rocketmq\Dto;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * rocketmq.consumer.receive 的请求。业务一般不直接构造，用 RocketmqConsumeSession::receive()。
 */
final class ReceiveMessageRequestDto extends AbstractDto
{
    /**
     * 要拉取的 Topic。
     */
    protected string $topic = '';

    /**
     * 消费组。同一组 + Topic 同时只能有一个 receive 在途，第二个返回 OVERLOADED。
     */
    protected string $consumerGroup = '';

    /**
     * 发给 sidecar 的 Tag 表达式：* 表示全部，多个 Tag 为 paid || cancel。
     * Handler 上的 Tag 数组由 RocketmqConsumeSession::tagExpression() 转成这个格式。
     */
    protected string $consumerTag = '*';

    /**
     * SQL92 属性过滤表达式，例如 eventType IN ('created','cancelled')。空字符串表示不用 SQL 过滤。
     * 非空时 consumerTag 必须是 *；broker 需要开启 enablePropertyFilter。
     */
    protected string $consumerSql = '';

    /**
     * 一次最多拉取条数，1–32。
     */
    protected int $maxNum = 2;

    /**
     * 拉到后的不可见时间（毫秒），10000–300000。期间不 ack，到期后消息重投。
     */
    protected int $invisibleMs = 30000;

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
     * 设置 Tag 表达式（已转换好的字符串，例如 * 或 paid || cancel）。
     */
    public function setConsumerTag(string $consumerTag): void
    {
        $this->consumerTag = $consumerTag;
    }

    /**
     * 读取 Tag 表达式。
     */
    public function getConsumerTag(): string
    {
        return $this->consumerTag;
    }

    /**
     * 设置 SQL92 过滤表达式，首尾空白会被去掉。
     */
    public function setConsumerSql(string $consumerSql): void
    {
        $this->consumerSql = trim($consumerSql);
    }

    /**
     * 读取 SQL92 过滤表达式，没有时为空串。
     */
    public function getConsumerSql(): string
    {
        return $this->consumerSql;
    }

    /**
     * 设置一次最多拉取条数，范围由调用方夹取。
     */
    public function setMaxNum(int $maxNum): void
    {
        $this->maxNum = $maxNum;
    }

    /**
     * 读取一次最多拉取条数。
     */
    public function getMaxNum(): int
    {
        return $this->maxNum;
    }

    /**
     * 设置不可见时间（毫秒），范围由调用方夹取。
     */
    public function setInvisibleMs(int $invisibleMs): void
    {
        $this->invisibleMs = $invisibleMs;
    }

    /**
     * 读取不可见时间（毫秒）。
     */
    public function getInvisibleMs(): int
    {
        return $this->invisibleMs;
    }

    /**
     * consumerSql 为空时不输出，请求和只支持 Tag 的旧 sidecar 保持一致。
     */
    public function toArray(): array
    {
        $out = [
            'topic' => $this->topic,
            'consumerGroup' => $this->consumerGroup,
            'consumerTag' => $this->consumerTag,
            'maxNum' => $this->maxNum,
            'invisibleMs' => $this->invisibleMs,
        ];
        if ($this->consumerSql !== '') {
            $out['consumerSql'] = $this->consumerSql;
        }

        return $out;
    }
}
