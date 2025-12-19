<?php
namespace Test\Controller;

use malkusch\lock\exception\TimeoutException;
use Swoolefy\Core\Controller\BController;
use Test\App;

class LockController extends BController
{
    public function locktest1()
    {
//        $lock = App::getRedisLock();
//        $result = $lock->synchronized(function () {
//            var_dump('test 1---获取到锁');
//            sleep(1);
//            return ['id' =>rand(1,10000)];
//        });

        $lock = App::getRedisLock();
        try {
            $result = $lock->synchronized(function () {
                var_dump('test 1---获取到锁='.date('Y-m-d H:i:s'));
                $result = App::getDb()->newQuery()->table('tbl_users')->limit(1)->select();
                sleep(10);
                // return ['id' =>rand(1,10000),'list' => $result];
                return 1111;
            });
            //var_dump($result);
            $this->returnJson([$result]);
        }catch (TimeoutException $e) {
            var_dump('test 1---锁等待超时='.date('Y-m-d H:i:s'));
            $this->returnJson(['tag' => '锁等待超时']);
        }
        $this->returnJson([
            'tag' => '锁未获取成功'
        ]);
    }

    public function locktest2()
    {
        $lock = App::getRedisLock();
        if ($lock->acquireLock()) {
            var_dump('test 2---获取到锁='.date('Y-m-d H:i:s'));
            sleep(8);
            $lock->releaseLock();
            return $this->returnJson(["tag" => "获取到锁",'id' =>rand(1,10000)]);
        }else {
            return $this->returnJson(["tag" => "未获取到锁", 'id' =>rand(1,10000)]);
        }
    }
}