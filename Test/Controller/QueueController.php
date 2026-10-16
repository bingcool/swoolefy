<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;
use Test\Process\ListQueueProcess\RedisListConsumer;

class QueueController extends BController
{
    /**
     * 向 Redis 键 queue:order:list 推一条 List 消息。连接用 queue.php 的 queue。
     * 数组由 Queue 编码。消费进程是 RedisListConsumer。
     *
     * Route: GET /api/queue/push (POST 亦可)
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/queue/push' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试队列 push 入队')]
    public function push(): array
    {
        (new ListQueueProducer(RedisListConsumer::QUEUE_ORDER_LIST_KEY))->push([
            'id' => random_int(1, 1000),
        ]);
        return ['id' => rand(1, 2)];
    }
}
