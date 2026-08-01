<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\ServiceDispatch;

/**
 * ServiceDispatch 最近一次失败原因走协程 Context，禁止 Worker 级 static 串号。
 */
final class ServiceDispatchLastErrorContextTest extends CoroutineTestCase
{
    /**
     * 两个并行协程各自 set 不同错误，互不污染。
     */
    public function testParallelCoroutinesDoNotPolluteLastDispatchError(): void
    {
        $this->runInCoroutine(function (): void {
            $ready = new Channel(2);
            $done = new Channel(2);

            Coroutine::create(static function () use ($ready, $done): void {
                ServiceDispatch::setLastDispatchError('error-from-A');
                $ready->push('A');
                // 等 B 写完后再读，确认 A 侧仍是自己的值
                $ready->pop(2);
                $done->push(ServiceDispatch::getLastDispatchError());
                ServiceDispatch::clearLastDispatchError();
            });

            Coroutine::create(static function () use ($ready, $done): void {
                ServiceDispatch::setLastDispatchError('error-from-B');
                $ready->push('B');
                $ready->pop(2);
                $done->push(ServiceDispatch::getLastDispatchError());
                ServiceDispatch::clearLastDispatchError();
            });

            $errors = [$done->pop(2), $done->pop(2)];
            sort($errors);
            $this->assertSame(['error-from-A', 'error-from-B'], $errors);
        });
    }

    /**
     * clear 后同协程 get 为 null；键落在 Context 常量上。
     */
    public function testClearRemovesContextKeyInSameCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            ServiceDispatch::setLastDispatchError('temporary');
            $this->assertSame('temporary', ServiceDispatch::getLastDispatchError());
            $this->assertTrue(Context::has(ServiceDispatch::LAST_DISPATCH_ERROR_CONTEXT_KEY));

            ServiceDispatch::clearLastDispatchError();
            $this->assertNull(ServiceDispatch::getLastDispatchError());
            $this->assertFalse(Context::has(ServiceDispatch::LAST_DISPATCH_ERROR_CONTEXT_KEY));
        });
    }
}
