<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use Swoolefy\Core\Hook;

/**
 * AFTER_REQUEST 执行后，同一轮协程的迟到注册不再留下静态残留。
 * 协程号被复用时，上一轮的桶直接丢掉，不在新协程里执行。
 */
final class HookSealTest extends CoroutineTestCase
{
    protected function tearDown(): void
    {
        foreach (['hooks', 'hookContext', 'hookSealed'] as $property) {
            $hooks = new ReflectionProperty(Hook::class, $property);
            $hooks->setAccessible(true);
            $hooks->setValue(null, []);
        }
        parent::tearDown();
    }

    public function testLateRegistrationAfterCallIsRejected(): void
    {
        $this->runInCoroutine(function (): void {
            $ran = ['first' => 0, 'late' => 0];
            $accepted = true;
            Hook::addHook(Hook::HOOK_AFTER_REQUEST, static function () use (&$ran, &$accepted): void {
                $ran['first']++;
                $accepted = Hook::addHook(Hook::HOOK_AFTER_REQUEST, static function () use (&$ran): void {
                    $ran['late']++;
                });
            });

            Hook::callHook(Hook::HOOK_AFTER_REQUEST);
            Hook::callHook(Hook::HOOK_AFTER_REQUEST);

            $this->assertFalse($accepted);
            $this->assertSame(1, $ran['first']);
            $this->assertSame(0, $ran['late']);
            $this->assertSame([], Hook::getHookCallable());
        });
    }

    public function testReusedCoroutineIdDropsThePreviousBucket(): void
    {
        $this->runInCoroutine(function (): void {
            $cid = \Swoole\Coroutine::getCid();
            $staleRan = false;
            $hooks = new ReflectionProperty(Hook::class, 'hooks');
            $hooks->setAccessible(true);
            $hooks->setValue(null, [
                $cid => [
                    Hook::HOOK_AFTER_REQUEST => [
                        'old' => static function () use (&$staleRan): void {
                            $staleRan = true;
                        },
                    ],
                ],
            ]);
            $context = new ReflectionProperty(Hook::class, 'hookContext');
            $context->setAccessible(true);
            $context->setValue(null, [$cid => 1]);

            Hook::callHook(Hook::HOOK_AFTER_REQUEST);
            $this->assertFalse($staleRan);
            $this->assertSame([], Hook::getHookCallable());

            $fresh = 0;
            $this->assertTrue(Hook::addHook(Hook::HOOK_AFTER_REQUEST, static function () use (&$fresh): void {
                $fresh++;
            }));
            Hook::callHook(Hook::HOOK_AFTER_REQUEST);
            $this->assertSame(1, $fresh);
            $this->assertFalse($staleRan);
        });
    }

    public function testHookSealedReleasedAfterCoroutineEnds(): void
    {
        $sealed = new ReflectionProperty(Hook::class, 'hookSealed');
        $sealed->setAccessible(true);

        $this->runInCoroutine(static function (): void {
            Hook::callHook(Hook::HOOK_AFTER_REQUEST);
        });

        $this->assertSame([], $sealed->getValue());
    }
}
