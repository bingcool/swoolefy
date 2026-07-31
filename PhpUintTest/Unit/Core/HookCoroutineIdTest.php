<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Core;

use PhpUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Core\Hook;

/**
 * 阶段二 4.6（审计项 23）：Hook 显式 coroutineId 不得串用。
 * 目标：传入 cid 时直接读取该桶；缺失返回空集合，不回退 cid=0。
 */
final class HookCoroutineIdTest extends TestCase
{
    protected function tearDown(): void
    {
        $hooks = new ReflectionProperty(Hook::class, 'hooks');
        $hooks->setAccessible(true);
        $hooks->setValue(null, []);
        parent::tearDown();
    }

    /**
     * 测同时存在 cid 0/A/B 的 Hook 时，显式读取互不串用。
     * 对应问题：旧 getHookCallable 忽略入参恒读 cid=0。
     */
    public function testExplicitCoroutineIdDoesNotFallBackToZero(): void
    {
        $hooks = new ReflectionProperty(Hook::class, 'hooks');
        $hooks->setAccessible(true);
        $fn0 = static function (): string {
            return 'cid0';
        };
        $fnA = static function (): string {
            return 'cidA';
        };
        $fnB = static function (): string {
            return 'cidB';
        };
        $hooks->setValue(null, [
            0 => [Hook::HOOK_AFTER_REQUEST => ['k0' => $fn0]],
            101 => [Hook::HOOK_AFTER_REQUEST => ['kA' => $fnA]],
            202 => [Hook::HOOK_AFTER_REQUEST => ['kB' => $fnB]],
        ]);

        $gotA = Hook::getHookCallable(101);
        $gotB = Hook::getHookCallable(202);
        $gotMissing = Hook::getHookCallable(999);

        $this->assertArrayHasKey(Hook::HOOK_AFTER_REQUEST, $gotA);
        $this->assertSame('cidA', $gotA[Hook::HOOK_AFTER_REQUEST]['kA']());
        $this->assertSame('cidB', $gotB[Hook::HOOK_AFTER_REQUEST]['kB']());
        $this->assertSame([], $gotMissing);
        $this->assertNotSame($gotA, Hook::getHookCallable(0));
    }
}
