<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;

/**
 * 协议服务里的延迟消费进程。业务只实现 onMessage()，并写上 $queue_component。
 *
 * $queue_component 是 queue.php 的组件名，默认 delayQueue。它不是 Redis 键。
 * 生产者要写同一个组件。组件必须是 phpredis 上的 RedisDelayQueue。
 *
 * 取出和确认：
 * - pop() 只取 score 已经到期的 1 条。取出时 ZSET 里这条已经删除。
 * - 还没到延迟秒数的消息不会出现在 onMessage()。
 * - onMessage() 正常返回：消费完成。
 * - onMessage() 抛异常：retry() 把原数组成员放回同一个 ZSET，1 秒后再次可见。
 *   __retry_num 加 1。这里把上限改成 32，超过后 Library 不再放回。
 * 业务要按自己的延迟再投一条新消息时，在 onMessage() 里 DelayQueueProducer::push() 并正常返回。
 */
abstract class DelayQueueConsumerProcessHandler extends AbstractProcess
{
    /**
     * queue.php 中的延迟组件名。子类覆盖它来消费别的延迟组件。
     */
    protected string $queue_component = 'delayQueue';

    private bool $running = true;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
    }

    public function run()
    {
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
     * pop(['limit' => [0, 1]]) 只取一条已到期消息。返回值是消息数组的列表，不是 [key, value]。
     * __timestamp 是入队时间，写入 DTO 的 readyAt。
     */
    private function pop(): ?ReceivedMessageDto
    {
        $result = QueueComponent::get($this->queue_component)->pop(['limit' => [0, 1]]);
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        $data = $result[0];
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);

        return new ReceivedMessageDto(
            payload: is_string($payload) ? $payload : '',
            queue: $this->queue_component,
            data: $data,
            readyAt: isset($data['__timestamp']) ? (int) $data['__timestamp'] : null,
        );
    }

    /**
     * 失败后 1 秒再可见。上限 32 次，避免 Job 的多次退避被组件默认的 3 次截断。
     */
    private function retry(ReceivedMessageDto $message): void
    {
        $data = $message->getData();
        if ($data === []) {
            return;
        }
        $queue = QueueComponent::get($this->queue_component);
        if (method_exists($queue, 'setRetryTimes')) {
            $queue->setRetryTimes(32);
        }
        $queue->retry($data, 1);
    }
}
