<?php

namespace Test\Process\QueueProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer;

/**
 * Redis 延迟队列生产。
 *
 * DelayQueueProducer 的 $queue_component 默认是 delayQueue。
 * push() 第二个参数是延迟秒数：这条消息 2 秒后才能被 Queue 消费进程 pop() 到。
 * 第一个参数是业务数组，不要 json_encode。组件写入 ZSET，score 是当前时间加 2 秒。
 */
class QueuePublish extends AbstractProcess
{
    public function run()
    {
        $producer = new DelayQueueProducer();
        while (true) {
            $producer->push([
                'order_id' => random_int(1000, 9999),
                'published_at' => date('Y-m-d H:i:s'),
            ], 2);
            \Swoole\Coroutine::sleep(2);
        }
    }
}
