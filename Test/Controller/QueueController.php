<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Support\Queue\ListQueue\ListQueueProducer;

class QueueController extends BController
{
    /**
     * 向组件 queue 推一条 List 消息。ListQueueProducer 默认的 queue_component 就是 queue。
     * 数组由组件编码并写入 Redis 键 queue:order:list。消费进程是 RedisList。
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
        (new ListQueueProducer())->push([
            'id' => random_int(1, 1000),
        ]);
        return ['id' => rand(1, 2)];
    }
}
