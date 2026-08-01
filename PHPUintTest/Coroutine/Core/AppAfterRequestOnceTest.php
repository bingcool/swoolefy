<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Hook;

/**
 * 阶段二 4.5（审计项 6）：afterRequest Hook 每个请求最多执行一次。
 * 目标：成功/异常路径均进入 finally 且不重复；Hook 异常不阻断后续 end。
 */
final class AppAfterRequestOnceTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
        $hooks = new ReflectionProperty(Hook::class, 'hooks');
        $hooks->setAccessible(true);
        $hooks->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $prop = new ReflectionProperty(Application::class, 'apps');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
        parent::tearDown();
    }

    /**
     * 测 onAfterRequest 在已进入请求处理后最多执行一次。
     * 对应问题：成功路径与 finally/异常路径可能重复执行 Session 保存 Hook。
     */
    public function testOnAfterRequestRunsAtMostOnce(): void
    {
        $this->runInCoroutine(function (): void {
            $app = new App();
            $count = 0;
            $app->afterRequest(static function () use (&$count): void {
                $count++;
            });

            $processing = new ReflectionProperty(App::class, 'requestProcessing');
            $processing->setAccessible(true);
            $processing->setValue($app, true);

            $method = new ReflectionMethod(App::class, 'onAfterRequest');
            $method->setAccessible(true);
            $method->invoke($app);
            $method->invoke($app);
            $method->invoke($app);

            $this->assertSame(1, $count);
        });
    }

    /**
     * 测 after Hook 抛错被吞掉后仍可再次标记为已调用（不重复）。
     * 对应问题：Hook 异常不得覆盖业务异常响应，也不得导致重复执行。
     */
    public function testAfterHookExceptionStillMarksCalled(): void
    {
        $this->runInCoroutine(function (): void {
            $app = new App();
            $count = 0;
            $app->afterRequest(static function () use (&$count): void {
                $count++;
                throw new \RuntimeException('hook-fail');
            });

            $processing = new ReflectionProperty(App::class, 'requestProcessing');
            $processing->setAccessible(true);
            $processing->setValue($app, true);

            $method = new ReflectionMethod(App::class, 'onAfterRequest');
            $method->setAccessible(true);
            $method->invoke($app);
            $method->invoke($app);

            $this->assertSame(1, $count);
        });
    }
}
