<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Support\Workflow;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\EventController;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\AI\Stream\StreamSinkInterface;
use Swoolefy\Support\Workflow\Engine\TimeoutGuard;
use Swoolefy\Support\Workflow\Exception\WorkflowTimeoutException;

/**
 * P0-03：Workflow 节点超时进入子协程后，仍需保留 Context / Application / StreamBridge。
 */
final class TimeoutGuardContextTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
        Application::removeApp();
    }

    protected function tearDown(): void
    {
        Application::removeApp();
        parent::tearDown();
    }

    public function testChildKeepsContextApplicationAndStreamSink(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('tenant', 'tenant-a');
            $sink = $this->newSink();
            StreamBridge::bind($sink);
            Application::setApp(new App());

            $observed = TimeoutGuard::runWithContext(static function (): array {
                StreamBridge::emit('token', ['delta' => 'x']);

                return [
                    'tenant' => Context::get('tenant'),
                    'app' => Application::getApp(),
                ];
            }, 5);

            $this->assertSame('tenant-a', $observed['tenant']);
            $this->assertInstanceOf(EventController::class, $observed['app']);
            $this->assertSame([['token', ['delta' => 'x']]], $sink->events);
            $this->assertInstanceOf(App::class, Application::getApp());
            Application::removeApp();
        });
    }

    public function testChildWithoutParentApplicationStaysUnbound(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('tenant', 'tenant-b');

            $observed = TimeoutGuard::runWithContext(static function (): array {
                return [
                    'tenant' => Context::get('tenant'),
                    'app' => Application::getApp(),
                ];
            }, 5);

            $this->assertSame('tenant-b', $observed['tenant']);
            $this->assertNull($observed['app']);
        });
    }

    public function testTimeoutExceptionAndChildAppCleanup(): void
    {
        $this->runInCoroutine(function (): void {
            Application::setApp(new App());
            $baselineApps = Application::countApps();
            $childCid = 0;

            try {
                TimeoutGuard::runWithContext(static function () use (&$childCid): void {
                    $childCid = Coroutine::getCid();
                    Coroutine::sleep(5);
                }, 0.05);
                $this->fail('expected WorkflowTimeoutException');
            } catch (WorkflowTimeoutException $e) {
                $this->assertStringContainsString('timed out', $e->getMessage());
            }

            // cancel 后在下一个事件循环轮次完成 defer 清理
            for ($i = 0; $i < 100 && Coroutine::exists($childCid); ++$i) {
                Coroutine::sleep(0.01);
            }

            $this->assertGreaterThan(0, $childCid);
            $this->assertFalse(Coroutine::exists($childCid));
            $this->assertInstanceOf(App::class, Application::getApp());
            $this->assertSame($baselineApps, Application::countApps());
            Application::removeApp();
        });
    }

    private function newSink(): object
    {
        return new class implements StreamSinkInterface {
            /** @var list<array{0:string,1:array<string,mixed>}> */
            public array $events = [];

            public function publish(string $event, array $payload = []): bool
            {
                $this->events[] = [$event, $payload];

                return true;
            }

            public function isOpen(): bool
            {
                return true;
            }
        };
    }
}
