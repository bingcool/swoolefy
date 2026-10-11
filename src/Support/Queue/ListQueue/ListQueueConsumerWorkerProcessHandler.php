<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 List 消费。退出和重启沿用 AbstractWorkerProcess 的 loopHandle()。
 *
 * $queue_component 是 queue.php 的组件名，默认 queue，不是 Redis 键。
 * 和 ListQueueConsumerProcessHandler 使用同一套 pop() / retry()：
 * - pop() 成功后消息已经离开 List。
 * - onMessage() 正常返回即消费完成。
 * - onMessage() 抛异常则 retry()，1 秒后回到 List，最多 5 次。
 * worker_num 保持 1。同一个 List 组件不要再挂第二个消费进程。
 */
abstract class ListQueueConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    /**
     * queue.php 中的 List 组件名。子类覆盖它来消费别的 List 组件。
     */
    protected string $queue_component = 'queue';

    private ?ReceivedMessageDto $pending = null;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    protected function loopHandle()
    {
        if ($this->waitToExit) {
            return;
        }
        if ($this->pending === null) {
            try {
                $this->pending = $this->pop();
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(0.5);

                return;
            }
            if ($this->pending === null) {
                \Swoole\Coroutine::sleep(0.2);

                return;
            }
        }
        if ($this->waitToExit) {
            return;
        }
        try {
            $this->onMessage($this->pending);
            $this->pending = null;
        } catch (\Throwable $e) {
            $this->retry($this->pending);
            $this->pending = null;
            $this->onHandleException($e);
            \Swoole\Coroutine::sleep(0.5);
        }
    }

    /**
     * Queue::pop() 返回 [redisKey, 消息数组]。第二个元素才是消息。
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
     * 失败后 1 秒再投递。这里把组件的重试上限改成 5，超过后 Library 不再放回队列。
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
