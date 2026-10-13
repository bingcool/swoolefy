<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Core\EventApp;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\QueueDeadLetter;

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
 *   $retry_delay_seconds 秒后再移回 List，__retry_num 加 1。
 * - 已重试 $max_retry_times 次仍失败，或消息体不是数组无法 retry：写入 {队列键}:dead_letter，见 QueueDeadLetter。
 * - retry() / 写死信时 Redis 出错：消息留在内存里，稍后只重做这一步，不会再次调用 onMessage()。
 *
 * 退出：SIGTERM 时 gracefulShutdownDrain() 最多等 $shutdown_wait_seconds 秒让当前这一轮跑完，
 * 再把还没交给 onMessage() 的消息放回 List、把待重试的消息补做 retry()。Redis 不可用时整条消息写进异常日志。
 * 进程被 kill -9 或 OOM 时，正在 onMessage() 里的那一条会丢失。需要严格不丢时用 Job 或 RocketMQ。
 *
 * 业务自己要换一条新消息重试时，在 onMessage() 里 push() 新数组并正常返回。不要再抛异常，否则原消息还会 retry()。
 */
abstract class ListQueueConsumerProcessHandler extends AbstractProcess
{
    /**
     * queue.php 中的 List 组件名。子类覆盖它来消费别的 List 组件。
     */
    protected string $queue_component = 'queue';

    /**
     * 最多重试次数，用完写死信。
     */
    protected int $max_retry_times = 5;

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

    /**
     * 收到 SIGTERM 后等待当前这一轮结束的最长秒数。
     */
    protected float $shutdown_wait_seconds = 5.0;

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

    private ?ListQueueOperator $operator = null;

    abstract public function onMessage(ReceivedMessageDto $message): void;

    public function gracefulShutdownDrain(): void
    {
        $this->running = false;
        if ($this->busy && \Swoole\Coroutine::getCid() > 0) {
            $deadline = microtime(true) + max(0.0, $this->shutdown_wait_seconds);
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

    private function operator(): ListQueueOperator
    {
        return $this->operator ??= new ListQueueOperator(
            $this->queue_component,
            $this->max_retry_times,
            $this->retry_delay_seconds,
            $this->dead_letter_max_length,
            $this->dead_letter_ttl_seconds,
        );
    }

    /**
     * Redis 不可用又要退出时，把整条消息写进异常日志，便于人工补发。
     */
    private function logLost(ReceivedMessageDto $message, \Throwable $e): void
    {
        $this->onHandleException(new \RuntimeException(
            'list queue message not requeued, queue=' . $message->getQueue() . ', payload=' . $message->getPayload(),
            0,
            $e
        ));
    }
}
