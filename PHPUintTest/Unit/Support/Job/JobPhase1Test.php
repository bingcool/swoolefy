<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Job;

use RuntimeException;
use Swoolefy\Support\Job\Exception\JobException;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobPublisher;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRetryPolicy;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\SupportLog;
use PHPUintTest\TestCase;

/**
 * Job Phase 1：信封、重试策略、Runner、Publisher（自脚本迁入）。
 */
final class JobPhase1Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SupportLog::setTestHandler(static function (): void {
        });
    }

    protected function tearDown(): void
    {
        SupportLog::resetTestHandler();
        parent::tearDown();
    }

    /**
     * 验证：JobEnvelope 序列化往返后 jobId、类型、载荷、元数据与重试次数保持一致。
     */
    public function testEnvelopeRoundTrip(): void
    {
        $job = JobEnvelope::make('order.paid.notify', ['orderId' => 1], [
            'tenantId' => 't1',
            'idempotencyKey' => 'order:1:paid-notify',
        ], new JobRetryPolicy(maxAttempts: 3));

        $again = JobEnvelope::fromArray($job->toArray());
        $this->assertSame($job->jobId, $again->jobId);
        $this->assertSame('order.paid.notify', $again->jobType);
        $this->assertSame(1, $again->payload['orderId'] ?? null);
        $this->assertSame('t1', $again->metaString('tenantId'));
        $this->assertSame(3, $again->maxAttempts);
        $this->assertSame(2, $again->withAttempt(2)->attempt);
    }

    /**
     * 验证：fromArray 缺少 jobType 时抛出 JobException。
     */
    public function testEnvelopeRequiresJobType(): void
    {
        $this->expectException(JobException::class);
        $this->expectExceptionMessage('jobType');
        JobEnvelope::fromArray(['payload' => []]);
    }

    /**
     * 验证：wrapLegacy 将旧格式数组包装为信封，已序列化的信封则保留原 jobType。
     */
    public function testWrapLegacy(): void
    {
        $job = JobEnvelope::wrapLegacy(['name' => 'legacy'], 'demo.legacy');
        $this->assertSame('demo.legacy', $job->jobType);
        $this->assertSame('legacy', $job->payload['name'] ?? null);

        $nested = JobEnvelope::wrapLegacy(JobEnvelope::make('x.y', ['a' => 1])->toArray(), 'ignored');
        $this->assertSame('x.y', $nested->jobType);
    }

    /**
     * 验证：JobRetryPolicy 按指数退避计算各次延迟，并在达到 maxAttempts 后停止重试。
     */
    public function testRetryBackoffIncreases(): void
    {
        $policy = new JobRetryPolicy(
            maxAttempts: 5,
            baseDelayMs: 1000,
            backoffMultiplier: 2.0,
            maxDelayMs: 300_000,
            jitterRatio: 0.0,
        );

        $this->assertSame(1000, $policy->delayMsForAttempt(1));
        $this->assertSame(2000, $policy->delayMsForAttempt(2));
        $this->assertSame(4000, $policy->delayMsForAttempt(3));
        $this->assertTrue($policy->shouldRetry(4));
        $this->assertFalse($policy->shouldRetry(5));
    }

    /**
     * 验证：Handler 返回 success 时 Runner 产出 SUCCESS，不触发重入队或死信。
     */
    public function testRunnerSuccessNoRequeue(): void
    {
        $requeued = 0;
        $dead = 0;
        $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0));
        $outcome = $runner->run(
            new StubSuccessHandler(),
            JobEnvelope::make('stub.ok', []),
            static function () use (&$requeued): void {
                ++$requeued;
            },
            static function () use (&$dead): void {
                ++$dead;
            },
        );

        $this->assertSame(JobRunOutcome::SUCCESS, $outcome);
        $this->assertSame(0, $requeued);
        $this->assertSame(0, $dead);
    }

    /**
     * 验证：Handler 请求重试时 Runner 返回 REQUEUED，attempt 递增且携带退避延迟。
     */
    public function testRunnerRequeueIncrementsAttempt(): void
    {
        $captured = null;
        $delay = null;
        $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 5, baseDelayMs: 100, jitterRatio: 0.0));
        $outcome = $runner->run(
            new StubAlwaysRetryHandler(),
            JobEnvelope::make('stub.retry', [], [], new JobRetryPolicy(maxAttempts: 5)),
            static function (JobEnvelope $next, int $delayMs) use (&$captured, &$delay): void {
                $captured = $next;
                $delay = $delayMs;
            },
            static function (): void {
                throw new RuntimeException('should not dead');
            },
        );

        $this->assertSame(JobRunOutcome::REQUEUED, $outcome);
        $this->assertInstanceOf(JobEnvelope::class, $captured);
        $this->assertSame(2, $captured->attempt);
        $this->assertSame(100, $delay);
    }

    /**
     * 验证：重试次数耗尽后 Runner 返回 DEAD 并将错误信息传给死信回调。
     */
    public function testRunnerExhaustedGoesDead(): void
    {
        $deadError = null;
        $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 2, jitterRatio: 0.0));
        $job = JobEnvelope::make('stub.poison', [], [], new JobRetryPolicy(maxAttempts: 2))
            ->withAttempt(2);
        $outcome = $runner->run(
            new StubAlwaysRetryHandler(),
            $job,
            static function (): void {
                throw new RuntimeException('should not requeue');
            },
            static function (JobEnvelope $j, string $error) use (&$deadError): void {
                $deadError = $error;
            },
        );

        $this->assertSame(JobRunOutcome::DEAD, $outcome);
        $this->assertSame('always', $deadError);
    }

    /**
     * 验证：Handler 显式 fail 时 Runner 直接产出 DEAD，不重入队。
     */
    public function testRunnerFailGoesDead(): void
    {
        $outcome = (new JobRunner())->run(
            new StubFailHandler(),
            JobEnvelope::make('stub.fail', []),
            static function (): void {
                throw new RuntimeException('no requeue');
            },
            static function (): void {
            },
        );
        $this->assertSame(JobRunOutcome::DEAD, $outcome);
    }

    /**
     * 验证：Handler 抛出未捕获异常时按可重试处理，Runner 返回 REQUEUED。
     */
    public function testRunnerExceptionTreatedAsRetry(): void
    {
        $outcome = (new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0)))->run(
            new StubThrowHandler(),
            JobEnvelope::make('stub.throw', []),
            static function (): void {
            },
            static function (): void {
                throw new RuntimeException('no dead');
            },
        );
        $this->assertSame(JobRunOutcome::REQUEUED, $outcome);
    }

    /**
     * 验证：JobPublisher dispatch 将完整信封（含 jobType、载荷、maxAttempts）交给发布回调。
     */
    public function testPublisherDispatch(): void
    {
        $published = null;
        $publisher = new JobPublisher(static function (array $data) use (&$published): void {
            $published = $data;
        }, new JobRetryPolicy(maxAttempts: 4));

        $job = $publisher->dispatch('order.paid.notify', ['orderId' => 9], ['tenantId' => 't9']);
        $this->assertIsArray($published);
        $this->assertSame('order.paid.notify', $published['jobType'] ?? null);
        $this->assertSame(9, $published['payload']['orderId'] ?? null);
        $this->assertSame(4, $published['maxAttempts'] ?? null);
        $this->assertSame($job->jobId, $published['jobId'] ?? null);
    }
}

final class StubSuccessHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.ok'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::success();
    }
}

final class StubAlwaysRetryHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.poison'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::retry('always');
    }
}

final class StubFailHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.fail'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::fail('bad payload');
    }
}

final class StubThrowHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.throw'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        throw new RuntimeException('boom');
    }
}
