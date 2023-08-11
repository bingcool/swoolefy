<?php
namespace Test\Controller;

use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Test\Factory;

class LockController extends BController
{
    public function locktest1()
    {
//        $lock = Factory::getRedisLock();
//        $result = $lock->synchronized(function () {
//            var_dump('test 1---获取到锁');
//            sleep(1);
//            return ['id' =>rand(1,10000)];
//        });


        $lock = Application::getApp()->get('predis-order-lock');
        $result = $lock->synchronized(function () {
            var_dump('test 1---获取到锁='.date('Y-m-d H:i:s'));
            $result = Factory::getDb()->newQuery()->table('tbl_users')->limit(1)->select();
            sleep(8);
            return ['id' =>rand(1,10000),'list' => $result];
        });
        $this->returnJson($result);
    }

    public function locktest2()
    {
        $lock = Factory::getRedisLock();
        $lock->synchronized(function () {
            var_dump('test 2---获取到锁');
        });
    }
}