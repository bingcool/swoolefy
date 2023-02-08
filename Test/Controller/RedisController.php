<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class RedisController extends BController
{

    public function testRedis()
    {
        /**
         * @var \Common\Library\Cache\Redis $redis
         */
        $redis = Application::getApp()->get('redis');
        $redis->set('name','bingcool-'.rand(1,1000));
        $value = $redis->get('name');
        $this->returnJson(['value' => $value]);
    }

    public function testPredis()
    {
        /**
         * @var \Common\Library\Cache\Predis $predis
         */
        $predis = Application::getApp()->get('predis');
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        $this->returnJson(['value' => $value]);
    }
}