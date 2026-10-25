<?php

declare(strict_types=1);

namespace Test\Process\ListQueueProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;

/**
 * Redis List 生产。
 *
 * 第一个参数是 Redis 键，和 RedisListConsumer 的 $queueKey 相同。
 * 第二个参数是 queue.php 的连接名，默认 queue。
 * push() 传业务数组，不要 json_encode。组件会补 __id、__retry_num、__timestamp 再 LPUSH。
 */
class RedisListPublish extends AbstractProcess
{
    /**
     * 队列组件名。
     */
    protected string $queueComponent = RedisListConsumer::QUEUE_COMPONENT;

    public function run()
    {
        $producer = new ListQueueProducer(RedisListConsumer::QUEUE_ORDER_LIST_KEY, $this->queueComponent);
        while (true) {
            // name、num 是业务字段。Redis 键已在构造 ListQueueProducer 时传入。
            $producer->push([
                'name' => 'bingcool',
                'num' => random_int(1, 10000),
            ]);
            \Swoole\Coroutine::sleep(2);
        }
    }
}
