<?php

namespace Test\Process\ListProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;

/**
 * Redis List 生产。
 *
 * ListQueueProducer 的 $queue_component 默认是 queue，对应 queue.php 里的 List 组件。
 * push() 传业务数组，不要 json_encode。组件会补 __id、__retry_num、__timestamp 再 LPUSH。
 * 消费端是 RedisList，它的 queue_component 也是 queue。
 */
class RedisListPublish extends AbstractProcess
{
    public function run()
    {
        $producer = new ListQueueProducer();
        while (true) {
            // name、num 是业务字段。Redis 键由组件决定，这里不传。
            $producer->push([
                'name' => 'bingcool',
                'num' => random_int(1, 10000),
            ]);
            \Swoole\Coroutine::sleep(2);
        }
    }
}
