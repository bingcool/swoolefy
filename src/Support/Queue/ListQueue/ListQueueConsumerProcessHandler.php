<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;

/**
 * 协议服务里的 List 消费进程。业务只实现 onMessage()，并写上 $queue_component。
 *
 * $queue_component 是 queue.php 的组件名，默认 queue。它不是 Redis 键。
 * 生产者要写同一个组件，消息才会到这个进程。
 *
 * 取出和确认：
 * - pop() 使用 Library\Queues\Queue::pop()。BRPOP 成功后消息已经离开 List。
 * - onMessage() 正常返回：什么都不再做，这条已经消费完。
 * - onMessage() 抛异常：用原数组调用 retry()。组件把它放进 {队列键}:retry_queue_sort，
 *   1 秒后再移回 List。__retry_num 加 1。达到 setRetryTimes 后 retry() 直接丢掉，不再回到队列。
 * 业务自己要换一条新消息重试时，在 onMessage() 里 push() 新数组并正常返回。不要再抛异常，否则原消息还会 retry()。
 */
abstract class ListQueueConsumerProcessHandler extends AbstractProcess
{
    /**
     * queue.php 中的 List 组件名。子类覆盖它来消费别的 List 组件。
     */
    protected string $queue_component = 'queue';

    private bool $running = true;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
        // 同一条先交给 onMessage()。抛异常才 retry，成功则丢掉内存里的引用。
        $pending = null;
        while ($this->running) {
            if ($pending === null) {
                try {
                    $pending = $this->pop();
                } catch (\Throwable $e) {
                    $this->onHandleException($e);
                    \Swoole\Coroutine::sleep(0.5);
                    continue;
                }
                if ($pending === null) {
                    \Swoole\Coroutine::sleep(0.2);
                    continue;
                }
            }
            if (!$this->running) {
                break;
            }
            try {
                $this->onMessage($pending);
                $pending = null;
            } catch (\Throwable $e) {
                $this->retry($pending);
                $pending = null;
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(0.5);
            }
        }
    }

    /**
     * Queue::pop() 返回 [redisKey, 消息数组]。没有消息时是空数组。
     * 第二个元素才是消息。redisKey 不交给业务。
     */
    private function pop(): ?ReceivedMessageDto
    {
        $result = QueueComponent::get($this->queue_component)->pop(1);
        if (!is_array($result) || !isset($result[1])) {
            return null;
        }
        $data = $result[1];
        if (is_array($data)) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

            return new ReceivedMessageDto(
                payload: is_string($payload) ? $payload : '',
                queue: $this->queue_component,
                data: $data,
            );
        }

        return new ReceivedMessageDto((string) $data, $this->queue_component);
    }

    /**
     * 失败后 1 秒再投递。组件默认最多重试 3 次，这里改成 5。
     * 没有数组体的消息无法 retry，直接结束。
     */
    private function retry(ReceivedMessageDto $message): void
    {
        $data = $message->getData();
        if ($data === []) {
            return;
        }
        $queue = QueueComponent::get($this->queue_component);
        if (method_exists($queue, 'setRetryTimes')) {
            $queue->setRetryTimes(5);
        }
        $queue->retry($data, 1);
    }
}
