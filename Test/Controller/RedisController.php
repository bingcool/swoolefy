<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\App;

class RedisController extends BController
{

    public function testRedis()
    {
        App::getRedis()->set('name','bingcool-'.rand(1,1000));
        $value = App::getRedis()->get('name');
        $this->returnJson(['value' => $value]);
    }

    public function testPredis()
    {
        $predis = App::getPredis();
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        $this->returnJson(['value' => $value]);
    }
}