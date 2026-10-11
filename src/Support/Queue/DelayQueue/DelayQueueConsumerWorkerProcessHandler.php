<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的延迟消费。退出和重启沿用 AbstractWorkerProcess 的 loopHandle()。
 *
 * $queue_component 是 queue.php 的组件名，默认 delayQueue，不是 Redis 键。
 * 和 DelayQueueConsumerProcessHandler 相同：只 pop 已到期的 1 条；onMessage() 正常返回即完成；
 * 抛异常则 1 秒后 retry()，最多 32 次。worker_num 保持 1。
 */
abstract class DelayQueueConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    /**
     * queue.php 中的延迟组件名。子类覆盖它来消费别的延迟组件。
     */
    protected string $queue_component = 'delayQueue';

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
     * 只取一条已到期消息。__timestamp 是入队时间，不是到期时间。
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
     * 失败后 1 秒再可见。上限 32 次。
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
