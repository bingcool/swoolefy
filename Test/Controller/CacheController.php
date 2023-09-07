<?php
namespace Test\Controller;

use Common\Library\Cache\Driver\RedisCache;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class CacheController extends BController
{
    public function test()
    {
        var_dump("parent cid =".\Swoole\Coroutine::getCid());
        \Swoolefy\Core\Coroutine\Context::set('name','kkkkkkkkkkkkkkkkkkkkkkkk');
        goApp(function () {
            goApp(function () {
                goApp(function () {
                    $arrayCopy = \Swoolefy\Core\Coroutine\Context::getContext()->getArrayCopy();
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

//        $this->returnJson([
//            'data' => $cache->get('bing-name')
//        ]);
    }
}