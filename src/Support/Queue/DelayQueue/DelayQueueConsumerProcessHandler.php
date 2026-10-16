<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Core\EventApp;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueDeadLetter;

/**
 * 协议服务里的延迟消费进程。业务实现 onMessage()，并写上 $queueComponent 和 $queueKey。
 *
 * $queueComponent 是 queue.php 的组件名，默认 delayQueue。它只提供 phpredis 连接。
 * $queueKey 是这个消费者自己的 ZSET 键。生产者要写同一个键。
 *
 * 取出和确认：
 * - pop() 只取 score 已经到期的 1 条。取出时 ZSET 里这条已经删除。
 * - 还没到延迟秒数的消息不会出现在 onMessage()。
 * - onMessage() 正常返回：消费完成。
 * - onMessage() 抛异常：retry() 把原数组成员放回同一个 ZSET，$retryDelaySeconds 秒后再次可见，__retry_num 加 1。
 * - 已重试 $maxRetryTimes 次仍失败，或消息体不是数组无法 retry：写入 {延迟键}:dead_letter，见 QueueDeadLetter。
 * - retry() / 写死信时 Redis 出错：消息留在内存里，稍后只重做这一步，不会再次调用 onMessage()。
 *
 * 退出：SIGTERM 时 gracefulShutdownDrain() 最多等 $shutdownWaitSeconds 秒让当前这一轮跑完，
 * 再把还没交给 onMessage() 的消息放回 ZSET（立即可见）、把待重试的消息补做 retry()。
 * 进程被 kill -9 或 OOM 时，正在 onMessage() 里的那一条会丢失。
 *
 * 业务要按自己的延迟再投一条新消息时，在 onMessage() 里 DelayQueueProducer::push() 并正常返回。
 */
abstract class DelayQueueConsumerProcessHandler extends AbstractProcess
{
    /**
     * queue.php 中的延迟组件名。子类覆盖它来消费别的延迟组件。
     */
    protected string $queueComponent = 'delayQueue';

    /**
     * 这个消费者自己的 Redis ZSET 键。子类必须覆盖。同一个键的生产者、消费者读写同一条延迟队列。
     */
    protected string $queueKey = '';

    /**
     * 最多重试次数，用完写死信。避免 Job 的多次退避被组件默认的 3 次截断。
     */
    protected int $maxRetryTimes = 32;

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

    /**
     * 收到 SIGTERM 后等待当前这一轮结束的最长秒数。
     */
    protected float $shutdownWaitSeconds = 5.0;

    private bool $running = true;

    /**
     * 主循环正在执行一轮（pop / onMessage / retry / sleep）。drain 只在它为 false 时接手 pending 和 failed。
     */
    private bool $busy = false;

    private ?ReceivedMessageDto $pending = null;

    /**
     * @var array{0: ReceivedMessageDto, 1: \Throwable}|null
     */
    private ?array $failed = null;

    private ?DelayQueueOperator $operator = null;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
        if ($this->busy && \Swoole\Coroutine::getCid() > 0) {
            $deadline = microtime(true) + max(0.0, $this->shutdownWaitSeconds);
            while ($this->busy && microtime(true) < $deadline) {
                \Swoole\Coroutine::sleep(0.05);
            }
        }
        if ($this->busy) {
            // 主循环还没让出，不能同时操作同一条消息
            if ($this->pending !== null) {
                $this->logLost($this->pending, new \RuntimeException('consumer still busy on shutdown'));
            }
            return;
        }

        EventApp::run(function () {
            if ($this->failed !== null) {
                [$message, $error] = $this->failed;
                $this->failed = null;
                try {
                    $this->operator()->fail($message, $error);
                } catch (\Throwable $e) {
                    $this->onHandleException($e);
                    $this->logLost($message, $e);
                }
            }
            if ($this->pending !== null) {
                $message = $this->pending;
                $this->pending = null;
                try {
                    $this->operator()->requeue($message);
                } catch (\Throwable $e) {
                    $this->onHandleException($e);
                    $this->logLost($message, $e);
                }
            }
        });
    }

    public function run()
    {
        while ($this->running) {
            $this->busy = true;
            try {
                $this->tick();
            } finally {
                $this->busy = false;
            }
        }
    }

    private function tick(): void
    {
        // 上一次 retry / 写死信没成功：只重做这一步，不再调用 onMessage()
        if ($this->failed !== null) {
            try {
                $this->operator()->fail($this->failed[0], $this->failed[1]);
                $this->failed = null;
            } catch (\Throwable $e) {
                $this->onHandleException($e);
                \Swoole\Coroutine::sleep(1);
                return;
            }
        }

        if ($this->pending === null) {
            try {
                $this->pending = $this->operator()->pop();
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

        // 退出中：pending 留给 gracefulShutdownDrain() 放回
        if (!$this->running) {
            return;
        }

        $message = $this->pending;
        $this->pending = null;
        try {
            $this->onMessage($message);
        } catch (\Throwable $e) {
            $this->onHandleException($e);
            $this->failed = [$message, $e];
            if ($this->running) {
                \Swoole\Coroutine::sleep(0.5);
            }
        }
    }

    private function operator(): DelayQueueOperator
    {
        return $this->operator ??= new DelayQueueOperator(
            $this->queueComponent,
            $this->queueKey,
            $this->maxRetryTimes,
            $this->retryDelaySeconds,
            $this->deadLetterMaxLength,
            $this->deadLetterTtlSeconds,
        );
    }

    /**
     * Redis 不可用又要退出时，把整条消息写进异常日志，便于人工补发。
     */
    private function logLost(ReceivedMessageDto $message, \Throwable $e): void
    {
        $this->onHandleException(new \RuntimeException(
            'delay queue message not requeued, queue=' . $message->getQueue() . ', payload=' . $message->getPayload(),
            0,
            $e
        ));
    }
}
