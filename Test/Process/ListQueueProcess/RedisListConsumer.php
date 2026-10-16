<?php

namespace Test\Process\ListQueueProcess;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\ListQueue\ListQueueConsumerProcessHandler;

/**
 * Redis List 消费。
 *
 * queue_order_list 是 Redis 键，只在 Config/component/queue.php 构造 Queue 时使用。
 * $queueComponent 是组件名 queue。生产和消费都写这个名字，才会读写同一个 List。
 * 不要把 $queueComponent 写成 queue:order:list。
 *
 * onMessage() 里：
 * - getQueue() 返回组件名 queue，不是 Redis 键。
 * - getData() 是数组，含业务字段和库字段 __id、__retry_num、__timestamp。
 * - getPayload() 是这个数组的 JSON。
 * 正常返回表示消费完成。抛异常会由基类 retry()，1 秒后再投递，最多 5 次。
 */
class RedisListConsumer extends ListQueueConsumerProcessHandler
{
    /**
     * Redis List 键。由 queue.php 的 queue 组件使用，消费者不要拿它去连接 Redis。
     */
    public const queue_order_list = 'queue:order:list';

    public const queue_component = 'queue';

    /**
     * queue.php 里的 List 组件名。
     */
    protected string $queueComponent = self::queue_component;

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
        ]);
    }
}
