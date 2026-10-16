<?php

declare(strict_types=1);

namespace Test\Process\JobProcess;

use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Queue\DelayQueue\DelayQueueConsumerProcessHandler;
use Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;
use Swoolefy\Support\Queue\QueueComponent;
use Test\Module\Job\OrderPaidNotifyHandler;

/**
 * 延迟队列上的单 Handler 消费。组件名是 delayQueue。
 *
 * 这里只处理到期后的 order.paid.notify。JobRedisMultiConsumer 把需要等待的任务放进这个组件。
 * pop() 只会拿到 score 已到期的消息。
 *
 * 信封上的 jobType 不是 order.paid.notify 时，转回 List 组件 queue，交给 JobRedisMultiConsumer。
 * 转回后正常返回。原延迟消息已经在 pop() 时从 ZSET 删除，不会再被本进程处理。
 *
 * 仍要重试时写回 delayQueue，延迟秒数由 delayMs 向上取整，至少 1 秒。
 * 死信使用 delayQueue 组件上的 Redis 连接。
 */
final class OrderNotifyConsumer extends DelayQueueConsumerProcessHandler
{
    /**
     * queue.php 的延迟组件。和 JobRedisMultiConsumer 的延迟重试是同一个组件。
     */
    protected string $queueComponent = 'delayQueue';

    public function onMessage(ReceivedMessageDto $message): void
    {
        $data = $message->getData();
        if ($data === []) {
            return;
        }

        $job = JobEnvelope::wrapLegacy($data, 'order.paid.notify');
        if ($job->jobType !== 'order.paid.notify') {
            // 不是本进程要处理的类型，放回 List 组件 queue。
            (new ListQueueProducer())->push($job->toArray());

            return;
        }

        $handler = new OrderPaidNotifyHandler();
        $runner = JobComponentFactory::runner();
        $dlq = JobComponentFactory::redisDeadLetter(
            QueueComponent::get($this->queueComponent)->getRedis(),
        );
        $runner->run(
            $handler,
            $job,
            requeue: static function (JobEnvelope $next, int $delayMs): void {
                // 继续延迟。第二个参数是秒，不是毫秒。
                $seconds = max(1, (int) ceil($delayMs / 1000));
                (new DelayQueueProducer())->push($next->toArray(), $seconds);
            },
            dead: static function (JobEnvelope $failed, string $error) use ($dlq): void {
                $dlq->push($failed, $error, 'default');
            },
        );
    }
}
