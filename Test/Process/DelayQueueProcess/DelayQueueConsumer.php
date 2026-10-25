<?php

declare(strict_types=1);

namespace Test\Process\DelayQueueProcess;

use Swoolefy\Support\Queue\DelayQueue\DelayQueueConsumerProcessHandler;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;

/**
 * Redis 延迟队列消费。
 *
 * $queueKey 是 Redis ZSET 键 queue:order:delay。生产者要写同一个键。
 * $queueComponent 是组件名 delayQueue，只取 phpredis 连接。它和 List 的 queue 不是同一个组件。
 *
 * pop() 只交出已经到期的消息。getReadyAt() 是入队时的 __timestamp，不是到期时间。
 * 到期时间是入队时间加上生产时的延迟秒数。
 * 正常返回即消费完成。抛异常会 retry()，1 秒后再次可见，最多 32 次。
 */
class DelayQueueConsumer extends DelayQueueConsumerProcessHandler
{
    /**
     * Redis ZSET 键。生产者、本消费者都用这个键创建 RedisDelayQueue。
     */
    public const QUEUE_ORDER_DELAY_KEY = 'queue:order:delay';

    /**
     * queue.php 里的延迟连接名。
     */
    public const QUEUE_COMPONENT = 'delayQueue';

    /**
     * Redis ZSET 键。生产者、本消费者都用这个键创建 RedisDelayQueue。
     */
    protected string $queueKey = self::QUEUE_ORDER_DELAY_KEY;

    /**
     * queue.php 里的延迟连接名。
     */
    protected string $queueComponent = self::QUEUE_COMPONENT;


    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            // 组件名 delayQueue，不是 Redis 键。
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
            // 入队 unix 时间。加上生产时的延迟秒数才是可见时间。
            'ready_at' => $message->getReadyAt(),
            'received_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
