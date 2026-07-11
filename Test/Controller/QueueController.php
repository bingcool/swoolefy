<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\App;

class QueueController extends BController
{
    /**
     * @Api 测试队列 push 入队
     *
     * curl -X GET 'http://127.0.0.1:9501/api/queue/push'
     */
    #[ApiOperation(description: '测试队列 push 入队')]
    public function push(): array
    {
        App::getQueue()->push(['id' => rand(1,1000)]);
        return ['id' => rand(1, 2)];
    }
}
