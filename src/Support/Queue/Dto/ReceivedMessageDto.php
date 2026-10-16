<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\Dto;

/**
 * 一条已经从队列组件 pop() 出来的消息。
 *
 * pop() 成功时，这条消息已经离开 Redis。onMessage() 正常返回就结束。
 * onMessage() 抛异常时，基类用 getData() 原样调用组件的 retry()。
 *
 * 字段分别是：
 * - payload：整条消息的 JSON 字符串，方便日志。里面包含业务字段，也包含库加上的 __id、__retry_num、__timestamp。
 * - queue：组件名，就是消费者的 $queueComponent，例如 queue、delayQueue。不是 Redis 键。
 * - data：json 解码后的数组。业务读这个。retry() 也要这个数组，不能改掉 __id。
 * - readyAt：延迟队列成员上的 __timestamp，是入队时的 unix 时间，不是“到期可见”的时间。
 *   List 队列没有这个字段，返回 null。到期时间是入队时间加上 push() 的延迟秒数，存在 ZSET 的 score 里。
 */
final class ReceivedMessageDto
{
    /**
     * @param array<string, mixed> $data Library 解码后的消息体，含 __id / __retry_num / __timestamp
     */
    public function __construct(
        private readonly string $payload,
        private readonly string $queue,
        private readonly array $data = [],
        private readonly ?int $readyAt = null,
    ) {
    }

    /**
     * 整条消息的 JSON。含业务字段和库字段。
     */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /**
     * 组件名，对应 $queueComponent。不是 Redis 键。
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * 解码后的数组。业务字段和 __id、__retry_num、__timestamp 都在里面。
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 延迟消息的入队时间。List 消息返回 null。
     */
    public function getReadyAt(): ?int
    {
        return $this->readyAt;
    }
}
