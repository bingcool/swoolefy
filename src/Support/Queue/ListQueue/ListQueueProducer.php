<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Support\Queue\QueueComponent;

/**
 * List 生产者。
 *
 * $queueComponent 是 Config/component/queue.php 的组件名，默认 queue。
 * 它不是 Redis 键。键名在 queue.php 里传给 Swoolefy\Library\Queues\Queue。
 * 要写到别的 List 组件时，子类覆盖这个属性，消费者使用同一个名字。
 *
 * push() 传入业务数组。Library 会补上：
 * - __id：消息 id
 * - __retry_num：已重试次数，新消息是 0
 * - __timestamp：入队 unix 时间
 * 然后 LPUSH 到该组件的 List。不要自己 json_encode，组件会编码。
 */
class ListQueueProducer
{
    /**
     * queue.php 里的 List 组件名。和消费者的 $queueComponent 必须相同，两边才是同一条队列。
     */
    protected string $queueComponent = 'queue';

    /**
     * @param string $queueComponent
     */
    public function __construct(string $queueComponent)
    {
        $this->queueComponent = $queueComponent;
    }

    /**
     * @param array<string, mixed> $item 业务字段。不要带 __id，组件会生成。
     */
    public function push(array $item): void
    {
        QueueComponent::get($this->queueComponent)->push($item);
    }
}
