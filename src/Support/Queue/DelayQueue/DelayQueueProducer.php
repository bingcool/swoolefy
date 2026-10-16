<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Library\Queues\RedisDelayQueue;
use Swoolefy\Support\Queue\QueueComponent;
use Swoolefy\Support\Queue\QueueException;

/**
 * 延迟队列生产者。
 *
 * $queueComponent 是 Config/component/queue.php 的组件名，默认 delayQueue。它只提供 phpredis 连接。
 * $queueKey 是 ZSET 键。和对应消费者的 $queueKey 必须相同。
 *
 * push() 的两个参数：
 * - $item：业务数组。组件补 __id、__retry_num、__timestamp 后写入 ZSET 的 member。
 * - $delaySeconds：多少秒之后才能被 pop()。0 表示当前就可以被取走。
 *   可见时间存在 ZSET 的 score 上，等于入队时间加上这个秒数。
 * addItem() 先放进内存，push() 再 ZADD。这里每次都立刻 push()，不攒批。
 */
class DelayQueueProducer
{
    private ?RedisDelayQueue $queue = null;

    /**
     * @param string $queueKey Redis ZSET 键。和消费者的 $queueKey 相同。
     * @param string $queueComponent queue.php 里的连接名，默认 delayQueue。必须是 phpredis。
     */
    public function __construct(
        private readonly string $queueKey,
        private readonly string $queueComponent = 'delayQueue',
    ) {
        if ($queueKey === '') {
            throw new QueueException('queueKey is required');
        }
    }

    /**
     * @param array<string, mixed> $item 业务字段。不要自己 json_encode。
     * @param int $delaySeconds 延迟秒数。0 表示立即可消费。
     */
    public function push(array $item, int $delaySeconds = 0): void
    {
        $this->queue()->addItem($item, $delaySeconds)->push();
    }

    private function queue(): RedisDelayQueue
    {
        return $this->queue ??= new RedisDelayQueue(QueueComponent::get($this->queueComponent), $this->queueKey);
    }
}
