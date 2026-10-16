<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\ListQueue;

use Swoolefy\Library\Queues\Queue;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Support\Queue\QueueException;

/**
 * List 生产者。
 *
 * $queueComponent 是 Config/component/queue.php 的组件名，默认 queue。它只提供 Redis 连接。
 * $queueKey 是这条 List 的 Redis 键。和对应消费者的 $queueKey 必须相同。
 *
 * push() 传入业务数组。Library 会补上：
 * - __id：消息 id
 * - __retry_num：已重试次数，新消息是 0
 * - __timestamp：入队 unix 时间
 * 然后 LPUSH 到 $queueKey。不要自己 json_encode，组件会编码。
 */
class ListQueueProducer
{
    private ?Queue $queue = null;

    /**
     * @param string $queueKey Redis List 键。和消费者的 $queueKey 相同。
     * @param string $queueComponent queue.php 里的连接名，默认 queue。
     */
    public function __construct(
        private readonly string $queueKey,
        private readonly string $queueComponent = 'queue',
    ) {
        if ($queueKey === '') {
            throw new QueueException('queueKey is required');
        }
    }

    /**
     * @param array<string, mixed> $item 业务字段。不要带 __id，组件会生成。
     */
    public function push(array $item): void
    {
        $this->queue()->push($item);
    }

    private function queue(): Queue
    {
        return $this->queue ??= new Queue(QueueComponent::get($this->queueComponent), $this->queueKey);
    }
}
