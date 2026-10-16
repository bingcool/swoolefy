<?php

namespace Test\Process\DelayQueueProcess;

use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Support\Queue\DelayQueue\DelayQueueProducer;

/**
 * Redis 延迟队列生产。
 *
 * 构造参数是 ZSET 键和 delayQueue 连接，和 DelayQueueConsumer 相同。
 * push() 第二个参数是延迟秒数：这条消息 2 秒后才能被消费进程 pop() 到。
 * 第一个参数是业务数组，不要 json_encode。组件写入 ZSET，score 是当前时间加 2 秒。
 */
class DelayQueuePublish extends AbstractProcess
{
    public function run()
    {
        $producer = new DelayQueueProducer(DelayQueueConsumer::QUEUE_ORDER_DELAY_KEY, DelayQueueConsumer::QUEUE_COMPONENT);
        while (true) {
            $producer->push([
                'order_id' => random_int(1000, 9999),
                'published_at' => date('Y-m-d H:i:s'),
            ], 2);
            \Swoole\Coroutine::sleep(2);
        }
    }
}
