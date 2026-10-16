<?php

declare(strict_types=1);

namespace Test\Process\JobProcess;

use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\ListQueue\ListQueueConsumerProcessHandler;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;
use Swoolefy\Support\Queue\QueueComponent;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * 多 jobType 的 List 消费。组件名是 queue，对应 queue.php 的 List。
 *
 * getData() 是 Job 信封数组，另外带有组件写入的 __id、__retry_num、__timestamp。
 * fromArray() 只取信封字段。没有 jobType 的消息直接返回，基类不会再 retry()。
 *
 * Runner 要求重试时，这里重新 push 一条新消息，然后正常返回。原消息已经在 pop() 时离开 List。
 * - delayMs 为 0：写回组件 queue，本进程马上可以再取到。
 * - delayMs 大于 0：写入组件 delayQueue，秒数由毫秒向上取整。到期后由 OrderNotifyConsumer 处理。
 * 不要在这里抛异常代替重试，否则基类还会对原消息再 retry() 一次。
 *
 * 超过次数的死信使用这个 List 组件上的 Redis 连接，键是 job:dead:default。
 */
final class JobRedisMultiConsumer extends ListQueueConsumerProcessHandler
{
    /**
     * queue.php 的 List 组件。立即重试也写回这个组件。
     */
    protected string $queueComponent = 'queue';

    public function onMessage(ReceivedMessageDto $message): void
    {
        $data = $message->getData();
        if ($data === [] || !isset($data['jobType'])) {
            return;
        }

        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
        );
        $runner = JobComponentFactory::runner();
        // 死信走组件自带的 Redis，不再单独 connect。
        $dlq = JobComponentFactory::redisDeadLetter(
            QueueComponent::get($this->queueComponent)->getRedis(),
        );
        $runner->runRegistered(
            $registry,
            JobEnvelope::fromArray($data),
            requeue: static function (JobEnvelope $next, int $delayMs): void {
                if ($delayMs > 0) {
                    // 延迟重试进 delayQueue。delayMs 是毫秒，push 的第二个参数是秒。
                    $seconds = max(1, (int) ceil($delayMs / 1000));
                    (new DelayQueueProducer())->push($next->toArray(), $seconds);

                    return;
                }
                // 立即重试进 queue。新消息会有新的 __id。
                (new ListQueueProducer())->push($next->toArray());
            },
            dead: static function (JobEnvelope $failed, string $error) use ($dlq): void {
                $dlq->push($failed, $error, 'default');
            },
        );
    }
}
