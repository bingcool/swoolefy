<?php

namespace Test\Process\ListQueueProcess;

use Swoolefy\Support\Queue\Dto\ReceivedMessageDto;
use Swoolefy\Support\Queue\ListQueue\ListQueueConsumerProcessHandler;

/**
 * Redis List 消费。
 *
 * $queueKey 是 Redis List 键 queue:order:list。生产者要写同一个键。
 * $queueComponent 是组件名 queue，只取 Redis 连接。
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
     * Redis List 键。生产者、本消费者都用这个键创建 Queue。
     */
    public const QUEUE_ORDER_LIST_KEY = 'queue:order:list';

    public const QUEUE_COMPONENT = 'queue';

    /**
     * queue.php 里的 List 连接名。
     */
    protected string $queueComponent = self::QUEUE_COMPONENT;

    protected string $queueKey = self::QUEUE_ORDER_LIST_KEY;

    public function onMessage(ReceivedMessageDto $message): void
    {
        var_dump([
            'queue' => $message->getQueue(),
            'payload' => $message->getPayload(),
        ]);
    }
}
