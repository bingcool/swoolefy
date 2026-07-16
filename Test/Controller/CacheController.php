<?php
namespace Test\Controller;

use Swoolefy\Library\Cache\Driver\RedisCache;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Controller\BController;

class CacheController extends BController
{
    /**
     * 测试 Redis Cache 读写与协程 Context 传递。
     *
     * Route: GET /api/cache/test
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/cache/test' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Redis Cache 读写与协程 Context 传递')]
    public function test(): array
    {
        var_dump("parent cid =".\Swoole\Coroutine::getCid());
        Context::set('name','kkkkkkkkkkkkkkkkkkkkkkkk');
        goApp(function () {
            goApp(function () {
                goApp(function () {
                    $arrayCopy = Context::getContext()->getArrayCopy();
                    var_dump($arrayCopy);
                });
            });
        });

        /**
         * @var RedisCache $cache
         */
        $cache = Application::getApp()->get('cache');
        $cache->set('bing-name',['name'=>'bingcool'], 10);
        var_dump($cache->get('bing-name'));

        return [
            'data' => $cache->get('bing-name')
        ];
    }


    /**
     * 测试 Cache setMultiple 批量写入。
     *
     * Route: GET /api/cache/test1 (POST /api/cache/test1 亦可；别名 GET /cache/test1)
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/cache/test1' \
       -H 'Accept: application/json'

     curl -X POST 'http://127.0.0.1:9501/api/cache/test1' \
       -H 'Accept: application/json'

     curl -X GET 'http://127.0.0.1:9501/cache/test1' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Cache setMultiple 批量写入')]
    public function test1(): array
    {
        var_dump("parent cid =".\Swoole\Coroutine::getCid());
        Context::set('name','kkkkkkkkkkkkkkkkkkkkkkkk');
        goApp(function () {
            goApp(function () {
                goApp(function () {
                    $arrayCopy = Context::getContext()->getArrayCopy();
                    //var_dump($arrayCopy);
                });
            });
        });

        /**
         * @var RedisCache $cache
         */
        $cache = Application::getApp()->get('cache');
        $data = [
            'bing-name' => ["name" => "bingcool"],
            'age' => '300',
            'city' => 'Beijing',
            'job' => 'Engineer'
        ];
        $cache->setMultiple($data, 600, false);
        var_dump($cache->get('age'));
        return [
            'data' => $cache->get('bing-name')
        ];
    }
}
