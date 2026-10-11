<?php

namespace Test\Process\QueueProcess;

use Swoolefy\Support\Queue\DelayQueue\DelayQueueConsumerProcessHandler;
use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;

/**
 * Redis 延迟队列消费。
 *
 * queue_order_list 是 Redis ZSET 键 queue:order:delay，只在 queue.php 构造 RedisDelayQueue 时使用。
 * queue_component 是组件名 delayQueue。它和 List 的 queue 不是同一个组件，键也不能相同。
 *
 * pop() 只交出已经到期的消息。getReadyAt() 是入队时的 __timestamp，不是到期时间。
 * 到期时间是入队时间加上生产时的延迟秒数。
 * 正常返回即消费完成。抛异常会 retry()，1 秒后再次可见，最多 32 次。
 */
class Queue extends DelayQueueConsumerProcessHandler
{
    /**
     * Redis ZSET 键。由 queue.php 的 delayQueue 组件使用。
     */
    public const queue_order_list = 'queue:order:delay';

    /**
     * queue.php 里的延迟组件名。
     */
    protected string $queue_component = 'delayQueue';

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            // 组件名 delayQueue，不是 Redis 键。
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
            // 入队 unix 时间。加上生产时的延迟秒数才是可见时间。
            'readyAt' => $message->getReadyAt(),
            'receivedAt' => date('Y-m-d H:i:s'),
        ]);
    }
}
