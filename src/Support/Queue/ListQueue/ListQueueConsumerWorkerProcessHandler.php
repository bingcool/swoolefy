<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueDeadLetter;
use Swoolefy\Worker\AbstractWorkerProcess;

/**
 * Daemon / Cron 常驻进程里的 List 消费。退出和重启沿用 AbstractWorkerProcess 的 loopHandle()。
 *
 * $queueComponent 是 queue.php 的组件名，默认 queue，只提供 Redis 连接。
 * $queueKey 是这个消费者自己的 Redis List 键。
 * 和 ListQueueConsumerProcessHandler 使用同一套 ListQueueOperator：
 * - pop() 成功后消息已经离开 List。
 * - onMessage() 正常返回即消费完成。
 * - onMessage() 抛异常则 retry()，$retryDelaySeconds 秒后回到 List，最多 $maxRetryTimes 次，用完写死信。
 * - retry() / 写死信时 Redis 出错：只重做这一步，不会再次调用 onMessage()。
 * 每次 loopHandle() 只处理一条，并在返回前收尾，不把消息留到下一轮：
 * 收到退出指令时，已取出、还没交给 onMessage() 的消息放回 List，然后 loopHandle() 返回，进程退出。
 * worker_num 保持 1。同一个 Redis 键只挂一个消费进程。
 */
abstract class ListQueueConsumerWorkerProcessHandler extends AbstractWorkerProcess
{
    /**
     * queue.php 中的 List 组件名。子类覆盖它来消费别的 List 组件。
     */
    protected string $queueComponent = 'queue';

    /**
     * 这个消费者自己的 Redis List 键。子类必须覆盖。
     */
    protected string $queueKey = '';

    /**
     * 最多重试次数，用完写死信。
     */
    protected int $maxRetryTimes = 5;

    /**
     * 每次重试的延迟秒数。
     */
    protected int $retryDelaySeconds = 1;

    /**
     * 死信列表最多保留的条数。<= 0 时按 QueueDeadLetter::DEFAULT_MAX_LENGTH 处理，不存在不截断的配置。
     */
    protected int $deadLetterMaxLength = QueueDeadLetter::DEFAULT_MAX_LENGTH;

    /**
     * 死信 key 的过期秒数，每次写入死信时刷新。<= 0 时按 QueueDeadLetter::DEFAULT_TTL_SECONDS 处理。
     */
    protected int $deadLetterTtlSeconds = QueueDeadLetter::DEFAULT_TTL_SECONDS;

    private ?ListQueueOperator $operator = null;

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

    private function operator(): ListQueueOperator
    {
        return $this->operator ??= new ListQueueOperator(
            $this->queueComponent,
            $this->queueKey,
            $this->maxRetryTimes,
            $this->retryDelaySeconds,
            $this->deadLetterMaxLength,
            $this->deadLetterTtlSeconds,
        );
    }

    private function logLost(ReceivedMessageDto $message, \Throwable $e): void
    {
        $this->onHandleException(new \RuntimeException(
            'list queue message not requeued, queue=' . $message->getQueue() . ', payload=' . $message->getPayload(),
            0,
            $e
        ));
    }
}
