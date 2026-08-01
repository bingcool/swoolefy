<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Coroutine\Context;

/**
 * 阶段二 4.4（审计项 5）：子协程 Context 快照传播与隔离。
 * 目标：子协程只得允许传播的副本，修改不反向污染父协程。
 */
final class ContextPropagationTest extends CoroutineTestCase
{
    /**
     * 测 goApp 子协程拿到标量副本，修改子协程值不影响父协程。
     * 对应问题：共享 ArrayObject / 对象引用会导致跨请求串数据。
     */
    public function testGoAppChildGetsCopyAndDoesNotPolluteParent(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('tenant', 'A');
            Context::set('score', 1);
            $childTenant = new Channel(1);
            $childSeesParentAfterMutate = new Channel(1);

            goApp(static function () use ($childTenant, $childSeesParentAfterMutate): void {
                $childTenant->push(Context::get('tenant'));
                Context::set('tenant', 'B');
                Context::set('score', 99);
                $childSeesParentAfterMutate->push(true);
            });

            $childSeesParentAfterMutate->pop(2);
            $this->assertSame('A', $childTenant->pop(0));
            $this->assertSame('A', Context::get('tenant'));
            $this->assertSame(1, Context::get('score'));
        });
    }

    /**
     * 测 snapshot 在协程内不包含对象，且无 TypeError。
     * 对应问题：对象不得进入可传播快照。
     */
    public function testSnapshotOmitsObjectsInsideCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('ok', 'yes');
            Context::set('obj', new \stdClass());
            $snap = Context::snapshot();
            $this->assertSame(['ok' => 'yes'], $snap);
        });
    }
}
