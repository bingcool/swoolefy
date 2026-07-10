<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\App;

class RedisController extends BController
{

    public function testRedis(): array
    {
        App::getRedis()->set('name','bingcool-'.rand(1,1000));
        $value = App::getRedis()->get('name');
        return ['value' => $value];
    }

    public function testPredis():  array
    {
        $predis = App::getPredis();
        $predis->set('predis-name','bingcool-'.rand(1,1000));
        $value = $predis->get('predis-name');
        return ['value' => $value];
    }
}