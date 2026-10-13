<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Support\Queue\QueueDeadLetter;

/**
 * DelayQueueConsumerProcessHandler 和 DelayQueueConsumerWorkerProcessHandler 共用的取出、重试、放回和死信。
 * 只调用 Library\Queues\RedisDelayQueue 已有的 pop() / retry() / getRedis() / getDelayKey()。
 */
final class DelayQueueOperator
{
    public function __construct(
        private readonly string $component,
        private readonly int $maxRetryTimes,
        private readonly int $retryDelaySeconds,
        private readonly int $deadLetterMaxLength,
        private readonly int $deadLetterTtlSeconds,
    ) {
    }

    /**
     * pop(['limit' => [0, 1]]) 只取一条已到期消息，取出时 ZSET 里已经删除。
     * __timestamp 是入队时间，写入 DTO 的 readyAt。
     */
    public function pop(): ?ReceivedMessageDto
    {
        $result = QueueComponent::get($this->component)->pop(['limit' => [0, 1]]);
        if (!is_array($result) || !isset($result[0])) {
            return null;
        }
        $data = $result[0];
        if (!is_array($data)) {
            return new ReceivedMessageDto((string) $data, $this->component);
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return new ReceivedMessageDto(
            payload: is_string($payload) ? $payload : '',
            queue: $this->component,
            data: $data,
            readyAt: isset($data['__timestamp']) ? (int) $data['__timestamp'] : null,
        );
    }

    /**
     * onMessage() 抛异常后调用。还有重试次数就 retry()，否则写死信。
     * Redis 写失败会抛出，调用方要保留这条消息稍后再调。
     */
    public function fail(ReceivedMessageDto $message, \Throwable $error): void
    {
        $queue = QueueComponent::get($this->component);
        $data = $message->getData();
        if ($data === []) {
            QueueDeadLetter::push($queue->getRedis(), $queue->getDelayKey(), $message, QueueDeadLetter::REASON_NOT_RETRYABLE, $error, $this->deadLetterMaxLength, $this->deadLetterTtlSeconds);
            return;
        }
        if ((int) ($data['__retry_num'] ?? 0) >= $this->maxRetryTimes) {
            QueueDeadLetter::push($queue->getRedis(), $queue->getDelayKey(), $message, QueueDeadLetter::REASON_RETRY_EXHAUSTED, $error, $this->deadLetterMaxLength, $this->deadLetterTtlSeconds);
            return;
        }
        if (method_exists($queue, 'setRetryTimes')) {
            $queue->setRetryTimes($this->maxRetryTimes);
        }
        $queue->retry($data, $this->retryDelaySeconds);
    }

    /**
     * 进程退出时把已取出、还没交给 onMessage() 的消息原样放回 ZSET，score 取当前时间，立即可见。
     * __id、__retry_num 不变，不算一次重试。
     */
    public function requeue(ReceivedMessageDto $message): void
    {
        $queue = QueueComponent::get($this->component);
        $queue->getRedis()->zAdd($queue->getDelayKey(), time(), $message->getPayload());
    }
}
