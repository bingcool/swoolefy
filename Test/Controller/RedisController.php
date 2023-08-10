<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\Factory;

class RedisController extends BController
{

    public function testRedis()
    {
        Factory::getRedis()->set('name','bingcool-'.rand(1,1000));
        $value = Factory::getRedis()->get('name');
        $this->returnJson(['value' => $value]);
    }

    public function testPredis()
    {
        $predis = Factory::getPredis();
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        $this->returnJson(['value' => $value]);
    }
}