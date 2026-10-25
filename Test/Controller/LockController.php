<?php

declare(strict_types=1);

namespace Test\Controller;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Swoole\Coroutine;
use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Library\Lock\RedisMutex;
use Swoolefy\Http\RequestInput;
use Test\App;
use Test\Common\LockVerify;

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
        $lock = App::getRedisLock();
        try {
            $result = $lock->synchronized(function () {
                var_dump('test 1---获取到锁='.date('Y-m-d H:i:s'));
                $result = App::getDb()->newQuery()->table('tbl_users')->limit(1)->select();
                sleep(10);
                return 1111;
            });
            return [$result];
        } catch (LockAcquireTimeoutException $e) {
            var_dump('test 1---锁等待超时='.date('Y-m-d H:i:s'));
            return ['tag' => '锁等待超时'];
        }
    }

    /**
     * 测试 Redis 分布式锁短等待非阻塞范式：拿不到锁立即走「稍后重试」分支。
     *
     * Route: GET /api/lock-test2
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/lock-test2' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: '测试 Redis 分布式锁短等待非阻塞范式')]
    public function locktest2(): array
    {
        $lock = new RedisMutex(App::getRedis()->getObject(), 'order_lock_rush', 0.2, 30.0);
        try {
            return $lock->synchronized(function () {
                Coroutine::sleep(0.3);
                return ['tag' => '获取到锁', 'id' => random_int(1, 10000)];
            });
        } catch (LockAcquireTimeoutException $e) {
            return ['tag' => '未获取到锁，请稍后重试', 'id' => random_int(1, 10000)];
        }
    }

    /**
     * 跨请求并发验证用：持锁 hold_ms 毫秒，返回进入/离开临界区的时间戳。
     *
     * Route: GET /api/lock/hold
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/lock/hold?name=lock_hold_demo&hold_ms=300&wait_seconds=5' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'Redis 锁跨请求并发验证入口')]
    public function lockhold(RequestInput $requestInput): array
    {
        $name = (string) $requestInput->input('name', '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $name)) {
            return ['acquired' => false, 'error' => 'invalid lock name'];
        }

        $holdMs = max(10, min(2000, (int) $requestInput->input('hold_ms', 200)));
        $wait = max(0.05, min(10.0, (float) $requestInput->input('wait_seconds', 5.0)));

        $lock = new RedisMutex(App::getRedis()->getObject(), $name, $wait, 10.0);
        $timestamps = [];
        try {
            $lock->synchronized(function () use ($holdMs, &$timestamps) {
                $timestamps['entered_at'] = microtime(true);
                Coroutine::sleep($holdMs / 1000);
                $timestamps['left_at'] = microtime(true);
            });
        } catch (LockAcquireTimeoutException $e) {
            return ['acquired' => false, 'waited_seconds' => $wait];
        }

        return [
            'acquired'   => true,
            'hold_ms'    => $holdMs,
            'entered_at' => $timestamps['entered_at'],
            'left_at'    => $timestamps['left_at'],
        ];
    }

    /**
     * Lock 模块端到端验证：同请求协程互斥、跨请求互斥、租期越界、短等待、
     * 跨驱动互斥、异常释放、实例隔离、包装器解包。输出 {"pass":bool,"cases":[...]}。
     *
     * Route: GET /api/lock/verify
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/lock/verify' -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(description: 'Lock 模块端到端验证')]
    public function lockverify(): array
    {
        return LockVerify::run();
    }
}
