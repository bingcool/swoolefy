<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueDeadLetter;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的延迟消费。退出和重启沿用 AbstractWorkerProcess 的 loopHandle()。
 *
 * $queue_component 是 queue.php 的组件名，默认 delayQueue，不是 Redis 键。
 * 和 DelayQueueConsumerProcessHandler 使用同一套 DelayQueueOperator：只 pop 已到期的 1 条；
 * onMessage() 正常返回即完成；抛异常则 $retry_delay_seconds 秒后 retry()，最多 $max_retry_times 次，用完写死信。
 * 每次 loopHandle() 只处理一条，并在返回前收尾：收到退出指令时，已取出、还没交给 onMessage() 的消息放回 ZSET。
 * worker_num 保持 1。
 */
abstract class DelayQueueConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    /**
     * queue.php 中的延迟组件名。子类覆盖它来消费别的延迟组件。
     */
    protected string $queue_component = 'delayQueue';

    /**
     * 最多重试次数，用完写死信。
     */
    protected int $max_retry_times = 32;

    /**
     * 每次重试的延迟秒数。
     */
    protected int $retry_delay_seconds = 1;

    /**
     * 死信列表最多保留的条数。<= 0 时按 QueueDeadLetter::DEFAULT_MAX_LENGTH 处理，不存在不截断的配置。
     */
    protected int $dead_letter_max_length = QueueDeadLetter::DEFAULT_MAX_LENGTH;

    /**
     * 死信 key 的过期秒数，每次写入死信时刷新。<= 0 时按 QueueDeadLetter::DEFAULT_TTL_SECONDS 处理。
     */
    protected int $dead_letter_ttl_seconds = QueueDeadLetter::DEFAULT_TTL_SECONDS;

    private ?DelayQueueOperator $operator = null;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    protected function loopHandle()
    {
        if ($this->waitToExit) {
            return;
        }
        try {
            $message = $this->operator()->pop();
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            \Swoole\Coroutine::sleep(0.5);
            return;
        }
        if ($message === null) {
            \Swoole\Coroutine::sleep(0.2);
            return;
        }
        if ($this->waitToExit) {
            $this->requeueOrLog($message);
            return;
        }
        try {
            $this->onMessage($message);
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            $this->failUntilDone($message, $e);
            if (!$this->waitToExit) {
                \Swoole\Coroutine::sleep(0.5);
            }
        }
    }

    /**
     * retry / 写死信失败时每秒重试，直到成功或收到退出指令。退出时把整条消息写进异常日志。
     */
    private function failUntilDone(ReceivedMessageDto $message, \Throwable $error): void
    {
        while (true) {
            try {
                $this->operator()->fail($message, $error);
                return;
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                if ($this->waitToExit) {
                    $this->logLost($message, $e);
                    return;
                }
                \Swoole\Coroutine::sleep(1);
            }
        }
    }

    private function requeueOrLog(ReceivedMessageDto $message): void
    {
        try {
            $this->operator()->requeue($message);
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            $this->logLost($message, $e);
        }
    }

    private function operator(): DelayQueueOperator
    {
        return $this->operator ??= new DelayQueueOperator(
            $this->queue_component,
            $this->max_retry_times,
            $this->retry_delay_seconds,
            $this->dead_letter_max_length,
            $this->dead_letter_ttl_seconds,
        );
    }

    private function logLost(ReceivedMessageDto $message, \Throwable $e): void
    {
        $this->onHandleException(new \RuntimeException(
            'delay queue message not requeued, queue=' . $message->getQueue() . ', payload=' . $message->getPayload(),
            0,
            $e
        ));
    }
}
