<?php
namespace Test\Controller;

use malkusch\lock\exception\TimeoutException;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\App;

class LockController extends BController
{
    /**
     * 测试 Redis 分布式锁 synchronized 阻塞获取。
     *
     * Route: GET /api/lock-test1
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/lock-test1' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Redis 分布式锁 synchronized 阻塞获取')]
    public function locktest1(): array
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
            return [$result];
        }catch (TimeoutException $e) {
            var_dump('test 1---锁等待超时='.date('Y-m-d H:i:s'));
            return ['tag' => '锁等待超时'];
        }
    }

    /**
     * 测试 Redis 分布式锁 acquire/release 非阻塞获取。
     *
     * Route: GET /api/lock-test2
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/lock-test2' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Redis 分布式锁 acquire/release 非阻塞获取')]
    public function locktest2(): array
    {
        $lock = App::getRedisLock();
        if ($lock->acquireLock()) {
            var_dump('test 2---获取到锁='.date('Y-m-d H:i:s'));
            sleep(8);
            $lock->releaseLock();
            return ["tag" => "获取到锁",'id' =>rand(1,10000)];
        }else {
            return ["tag" => "未获取到锁", 'id' =>rand(1,10000)];
        }
    }
}
