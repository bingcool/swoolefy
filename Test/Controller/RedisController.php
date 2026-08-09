<?php
namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\App;

class RedisController extends BController
{

    /**
     * 测试 Redis 扩展读写。
     *
     * Route: GET /api/redis/test
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/redis/test' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Redis 扩展读写')]
    public function testRedis(): array
    {
        App::getRedis()->set('name','bingcool-'.rand(1,1000));
        $value = App::getRedis()->get('name');
        return ['value' => $value];
    }

    /**
     * 测试 Predis 客户端读写。
     *
     * Route: GET /api/redis/predis
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/redis/predis' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Predis 客户端读写')]
    public function testPredis():  array
    {
        $predis = App::getPredis();
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');

        var_dump(spl_object_id($predis));

        $predis1 = App::getPredis();
        var_dump(spl_object_id($predis1));

        return ['value' => $value];
    }
}
