<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use Swoolefy\Core\Application;
use Swoolefy\Core\App;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\EventController;
use Swoolefy\Exception\SystemException;
use Swoole\Coroutine\Channel;

/**
 * 阶段二 4.2/4.3（审计项 3/4）：EventApp::run 唯一入口与禁止覆盖当前请求 App。
 * 目标：同步事件结束后 Application 无残留；同协程覆盖抛错；goApp 子协程可创建。
 */
final class EventAppLifecycleTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
        $this->clearApps();
    }

    protected function tearDown(): void
    {
        $this->clearApps();
        parent::tearDown();
    }

    /**
     * 测同步（cid&lt;0）EventApp::run 成功与异常后 apps[-1] 均不存在。
     * 对应问题：同步事件无 defer 时 Application 泄漏在常驻进程。
     */
    public function testSyncRunClearsApplicationOnSuccessAndException(): void
    {
        EventApp::run(function (): void {
            $this->assertTrue(Application::issetApp());
        });
        $this->assertFalse(Application::issetApp(-1));
        $this->assertSame(0, Application::countApps());

        try {
            EventApp::run(function (): void {
                throw new \RuntimeException('biz-fail');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('biz-fail', $e->getMessage());
        }
        $this->assertSame(0, Application::countApps());
    }

    /**
     * 测 HTTP App 已绑定的协程内直接创建 EventApp 失败，原 App 保持不变。
     * 对应问题：EventController 构造会覆盖请求级 Application。
     */
    public function testCannotOverwriteHttpAppInSameCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            $app = new App();
            Application::setApp($app);
            $before = Application::getApp();

            try {
                EventApp::run(function (): void {
                });
                $this->fail('expected SystemException');
            } catch (SystemException $e) {
                $this->assertStringContainsString('goApp()', $e->getMessage());
            }

            $this->assertSame($before, Application::getApp());
            Application::removeApp();
        });
    }

    /**
     * 测 goApp 子协程内创建 EventApp 正常，结束后无残留。
     * 对应问题：正确隔离应在子协程新建生命周期。
     */
    public function testGoAppChildCanCreateEventApp(): void
    {
        $this->runInCoroutine(function (): void {
            $app = new App();
            Application::setApp($app);
            $ok = new Channel(1);
            goApp(static function () use ($ok): void {
                $ok->push(Application::getApp() instanceof EventController);
            });
            $this->assertTrue($ok->pop(2));
            $this->assertSame($app, Application::getApp());
            Application::removeApp();
        });
    }

    private function clearApps(): void
    {
        $prop = new ReflectionProperty(Application::class, 'apps');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
