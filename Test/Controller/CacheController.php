<?php
namespace Test\Controller;

use Common\Library\Cache\Driver\RedisCache;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Controller\BController;

class CacheController extends BController
{
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