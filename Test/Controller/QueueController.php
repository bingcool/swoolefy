<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\App;

class QueueController extends BController
{
    /**
     * 测试队列 push 入队。
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
        App::getQueue()->push(['id' => rand(1,1000)]);
        return ['id' => rand(1, 2)];
    }
}
