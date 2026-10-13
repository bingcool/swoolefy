<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Support\Queue\QueueDeadLetter;

/**
 * ListQueueConsumerProcessHandler 和 ListQueueConsumerWorkerProcessHandler 共用的取出、重试、放回和死信。
 * 只调用 Library\Queues\Queue 已有的 pop() / retry() / getRedis() / getQueueKey()，不另起一套协议。
 */
final class ListQueueOperator
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
     * Queue::pop() 返回 [redisKey, 消息]。第二个元素才是消息。BRPOP 成功后消息已经离开 List。
     */
    public function pop(): ?ReceivedMessageDto
    {
        $result = QueueComponent::get($this->component)->pop(1);
        if (!is_array($result) || !isset($result[1])) {
            return null;
        }
        $data = $result[1];
        if (is_array($data)) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            return new ReceivedMessageDto(
                payload: is_string($payload) ? $payload : '',
                queue: $this->component,
                data: $data,
            );
        }

        return new ReceivedMessageDto((string) $data, $this->component);
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
            QueueDeadLetter::push($queue->getRedis(), $queue->getQueueKey(), $message, QueueDeadLetter::REASON_NOT_RETRYABLE, $error, $this->deadLetterMaxLength, $this->deadLetterTtlSeconds);
            return;
        }
        if ((int) ($data['__retry_num'] ?? 0) >= $this->maxRetryTimes) {
            QueueDeadLetter::push($queue->getRedis(), $queue->getQueueKey(), $message, QueueDeadLetter::REASON_RETRY_EXHAUSTED, $error, $this->deadLetterMaxLength, $this->deadLetterTtlSeconds);
            return;
        }
        if (method_exists($queue, 'setRetryTimes')) {
            $queue->setRetryTimes($this->maxRetryTimes);
        }
        $queue->retry($data, $this->retryDelaySeconds);
    }

    /**
     * 进程退出时把已取出、还没交给 onMessage() 的消息原样放回 List 的出队端，下一个消费者先拿到它。
     * __id、__retry_num 不变，不算一次重试。
     */
    public function requeue(ReceivedMessageDto $message): void
    {
        $queue = QueueComponent::get($this->component);
        $queue->getRedis()->rPush($queue->getQueueKey(), $message->getPayload());
    }
}
