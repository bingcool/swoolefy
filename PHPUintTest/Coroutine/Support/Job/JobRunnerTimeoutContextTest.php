<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Support\Job;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoolefy\Core\App;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\EventController;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\AI\Stream\StreamSinkInterface;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRetryPolicy;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\SupportLog;

/**
 * P0-03 同类问题：JobRunner 的 handler 超时子协程必须保留 Context / Application / StreamBridge，
 * 否则消费进程里的 Handler 读不到租户上下文、拿不到协程应用，也无法emit流式事件。
 */
final class JobRunnerTimeoutContextTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
        Application::removeApp();
        SupportLog::setTestHandler(static function (): void {
        });
    }

    protected function tearDown(): void
    {
        SupportLog::resetTestHandler();
        Application::removeApp();
        parent::tearDown();
    }

    /**
     * 验证：超时预算充足时 handler 仍能看到父协程的 Context 标量、Application 与流式 sink。
     */
    public function testHandlerSeesContextApplicationAndSink(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('tenant', 'tenant-job');
            $sink = $this->newSink();
            StreamBridge::bind($sink);
            Application::setApp(new App());

            $observed = new \ArrayObject();
            $outcome = (new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0), 5.0))->run(
                new ContextReadingHandler($observed),
                JobEnvelope::make('stub.context', []),
                static function (): void {
                    throw new \RuntimeException('should not requeue');
                },
                static function (): void {
                    throw new \RuntimeException('should not dead');
                },
            );

            $this->assertSame(JobRunOutcome::SUCCESS, $outcome);
            $this->assertSame('tenant-job', $observed['tenant']);
            $this->assertInstanceOf(EventController::class, $observed['app']);
            $this->assertSame([['token', ['delta' => 'x']]], $sink->events);
            // 子协程用完即归还，父协程自己的 App 不受影响
            $this->assertInstanceOf(App::class, Application::getApp());
            Application::removeApp();
        });
    }

    /**
     * 验证：父协程没有 Application 时不凭空绑定，只恢复 Context 快照。
     */
    public function testHandlerWithoutParentApplicationStaysUnbound(): void
    {
        $this->runInCoroutine(function (): void {
            Context::set('tenant', 'tenant-nobind');

            $observed = new \ArrayObject();
            $outcome = (new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0), 5.0))->run(
                new ContextReadingHandler($observed),
                JobEnvelope::make('stub.context', []),
                static function (): void {
                },
                static function (): void {
                },
            );

            $this->assertSame(JobRunOutcome::SUCCESS, $outcome);
            $this->assertSame('tenant-nobind', $observed['tenant']);
            $this->assertNull($observed['app']);
        });
    }

    /**
     * 验证：超时被 cancel 后，子协程临时创建的 App 不会残留在应用表里。
     */
    public function testTimeoutCancelsChildWithoutLeakingAppOrCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            Application::setApp(new App());
            $baselineApps = Application::countApps();
            $record = new \ArrayObject();

            $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 3, baseDelayMs: 10, jitterRatio: 0.0), 0.05);
            $outcome = $runner->run(
                new SleepyRecorderHandler($record),
                JobEnvelope::make('stub.slow', [], [], new JobRetryPolicy(maxAttempts: 3)),
                static function (): void {
                },
                static function (): void {
                    throw new \RuntimeException('should not dead on first timeout');
                },
            );

            $this->assertSame(JobRunOutcome::REQUEUED, $outcome);

            for ($i = 0; $i < 100 && Coroutine::exists((int) ($record['cid'] ?? 0)); ++$i) {
                Coroutine::sleep(0.01);
            }

            $childCid = (int) ($record['cid'] ?? 0);
            $this->assertGreaterThan(0, $childCid);
            $this->assertFalse(Coroutine::exists($childCid));
            $this->assertSame($baselineApps, Application::countApps());
            $this->assertInstanceOf(App::class, Application::getApp());
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

/** 读取当前协程上下文的 Handler，用于断言超时子协程继承了父协程上下文。 */
final class ContextReadingHandler implements JobHandlerInterface
{
    /**
     * @param \ArrayObject<string,mixed> $observed 由外部持有，handler 写入观测结果
     */
    public function __construct(private readonly \ArrayObject $observed)
    {
    }

    public function types(): array
    {
        return ['stub.context'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        unset($job);
        StreamBridge::emit('token', ['delta' => 'x']);
        $this->observed['tenant'] = Context::get('tenant');
        $this->observed['app'] = Application::getApp();

        return JobResult::success();
    }
}

/** sleep 超过超时预算的 Handler，同时记录子协程 cid 供 cancel 断言。 */
final class SleepyRecorderHandler implements JobHandlerInterface
{
    /**
     * @param \ArrayObject<string,int> $record 记录子协程 cid
     */
    public function __construct(private readonly \ArrayObject $record)
    {
    }

    public function types(): array
    {
        return ['stub.slow'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        unset($job);
        $this->record['cid'] = Coroutine::getCid();
        Coroutine::sleep(5);

        return JobResult::success();
    }
}
