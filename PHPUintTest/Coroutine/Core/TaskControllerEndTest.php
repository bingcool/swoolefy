<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Core;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use Swoole\Http\Server;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;
use Swoolefy\Http\HttpAppServer;

/**
 * 阶段二 4.1（审计项 3）：TaskController 统一在 finally end。
 * 目标：成功、业务异常路径在无 defer 时均释放 Application；end 幂等。
 */
final class TaskControllerEndTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
        $this->clearApps();
        FakeTaskForEndTest::$ended = false;
        FakeTaskForEndTest::$throw = false;
        FakeTaskForEndTest::$constructedWithDefer = false;
    }

    protected function tearDown(): void
    {
        $this->clearApps();
        parent::tearDown();
    }

    /**
     * 测成功路径在非协程（无 defer）下 finally 释放 Application。
     * 对应问题：task_enable_coroutine=false 时成功路径从不 end。
     */
    public function testOnTaskSuccessEndsWithoutDefer(): void
    {
        FakeTaskForEndTest::$throw = false;
        $server = $this->createStub(Server::class);
        $httpApp = $this->makeHttpAppServer();

        $httpApp->onTask($server, 1, 0, [
            [FakeTaskForEndTest::class, 'handle'],
            ['x' => 1],
            [],
            null,
        ]);

        $this->assertSame(0, Application::countApps());
        $this->assertTrue(FakeTaskForEndTest::$ended);
        $this->assertFalse(FakeTaskForEndTest::$constructedWithDefer);
    }

    /**
     * 测业务异常路径仍会 end，且异常继续抛出。
     * 对应问题：仅 catch 中 end 时成功路径漏清理；finally 统一释放。
     */
    public function testOnTaskBusinessExceptionStillEnds(): void
    {
        FakeTaskForEndTest::$throw = true;
        $server = $this->createStub(Server::class);
        $httpApp = $this->makeHttpAppServer();

        try {
            $httpApp->onTask($server, 2, 0, [
                [FakeTaskForEndTest::class, 'handle'],
                [],
                [],
                null,
            ]);
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('task-biz', $e->getMessage());
        }

        $this->assertTrue(FakeTaskForEndTest::$ended);
        $this->assertSame(0, Application::countApps());
    }

    /**
     * 测已 defer 时 onTask finally 不重复主动 end（由协程 defer 接管）。
     * 对应问题：协程 Task 重复 end 可能二次清理组件。
     */
    public function testOnTaskSkipsEndWhenDeferEnabled(): void
    {
        $this->runInCoroutine(function (): void {
            FakeTaskForEndTest::$throw = false;
            FakeTaskForEndTest::$ended = false;
            $server = $this->createStub(Server::class);
            $httpApp = $this->makeHttpAppServer();

            $httpApp->onTask($server, 3, 0, [
                [FakeTaskForEndTest::class, 'handle'],
                [],
                [],
                null,
            ]);

            $this->assertTrue(FakeTaskForEndTest::$constructedWithDefer);
        });
    }

    private function makeHttpAppServer(): HttpAppServer
    {
        return new class extends HttpAppServer {
            public function __construct()
            {
                // 单测不启动真实 Server，跳过父构造
            }

            public function onWorkerStart(Server $server, int $worker_id)
            {
            }

            public function onPipeMessage(Server $server, int $from_worker_id, $message)
            {
            }
        };
    }

    private function clearApps(): void
    {
        $prop = new ReflectionProperty(Application::class, 'apps');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}

/**
 * onTask 用的轻量 Task 替身（继承 EventController，绕过 TaskController 进程校验）。
 */
class FakeTaskForEndTest extends EventController
{
    public static bool $ended = false;

    public static bool $throw = false;

    public static bool $constructedWithDefer = false;

    protected $taskId;

    protected $fromWorkerId;

    protected $task = null;

    public function __construct()
    {
        parent::__construct();
        self::$constructedWithDefer = $this->isDefer();
    }

    public function setTaskId(int $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function setFromWorkerId(int $fromWorkerId): void
    {
        $this->fromWorkerId = $fromWorkerId;
    }

    public function setTask($task): void
    {
        $this->task = $task;
    }

    public function handle($data = null): void
    {
        if (self::$throw) {
            throw new \RuntimeException('task-biz');
        }
    }

    public function end()
    {
        self::$ended = true;
        parent::end();
    }
}
