<?php

declare(strict_types=1);

namespace Swoolefy\Support\Queue\DelayQueue;

use Swoolefy\Support\Queue\QueueComponent;

/**
 * 延迟队列生产者。
 *
 * $queue_component 是 Config/component/queue.php 的组件名，默认 delayQueue。
 * 它不是 Redis 键。键名在 queue.php 里传给 Swoolefy\Library\Queues\RedisDelayQueue。
 * 这个组件必须是 phpredis 上的 RedisDelayQueue，不能换成 Predis。
 *
 * push() 的两个参数：
 * - $item：业务数组。组件补 __id、__retry_num、__timestamp 后写入 ZSET 的 member。
 * - $delaySeconds：多少秒之后才能被 pop()。0 表示当前就可以被取走。
 *   可见时间存在 ZSET 的 score 上，等于入队时间加上这个秒数。
 * addItem() 先放进内存，push() 再 ZADD。这里每次都立刻 push()，不攒批。
 */
class DelayQueueProducer
{

    /**
     * queue.php 里的延迟组件名。和消费者的 $queue_component 必须相同。
     */
    protected string $queue_component = 'delayQueue';

    /**
     * @param string $queueComponent
     */
    public function __construct(string $queueComponent)
    {
        $this->queue_component = $queueComponent;
    }

    /**
     * @param array<string, mixed> $item 业务字段。不要自己 json_encode。
     * @param int $delaySeconds 延迟秒数。0 表示立即可消费。
     */
    public function push(array $item, int $delaySeconds = 0): void
    {
        QueueComponent::get($this->queue_component)->addItem($item, $delaySeconds)->push();
    }
}
