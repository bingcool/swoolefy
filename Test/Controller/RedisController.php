<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\App;

class RedisController extends BController
{

    /**
     * @Api 测试 Redis 扩展读写
     *
     * curl -X GET 'http://127.0.0.1:9501/api/redis/test'
     */
    #[ApiOperation(description: '测试 Redis 扩展读写')]
    public function testRedis(): array
    {
        App::getRedis()->set('name','bingcool-'.rand(1,1000));
        $value = App::getRedis()->get('name');
        return ['value' => $value];
    }

    /**
     * @Api 测试 Predis 客户端读写
     *
     * curl -X GET 'http://127.0.0.1:9501/api/redis/predis'
     */
    #[ApiOperation(description: '测试 Predis 客户端读写')]
    public function testPredis():  array
    {
        $predis = App::getPredis();
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        return ['value' => $value];
    }
}
