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
        /**
         * @var RedisCache $cache
         */
        $cache = Application::getApp()->get('cache');
        $cache->set('bing-name',['name'=>'bingcool'], 10);
        var_dump($cache->get('bing-name'));

        $this->returnJson([
            'data' => $cache->get('bing-name')
        ]);
    }
}