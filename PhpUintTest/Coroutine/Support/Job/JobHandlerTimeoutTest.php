<?php

declare(strict_types=1);

namespace PhpUintTest\Coroutine\Support\Job;

use PhpUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRetryPolicy;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\SupportLog;

/**
 * 阶段六 8.2：验证 JobRunner 的 handler_timeout_seconds 经 TimeoutGuard 真正生效。
 *
 * 覆盖：正常完成、协程 sleep 超时→REQUEUED、超时且重试耗尽→DEAD、业务抛异常→RETRY。
 */
final class JobHandlerTimeoutTest extends CoroutineTestCase
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
     * 验证：未超时的 Handler 正常返回 SUCCESS，不触发 requeue/dead。
     */
    public function testHandlerCompletesWithinTimeout(): void
    {
        $this->runInCoroutine(function (): void {
            $requeued = 0;
            $dead = 0;
            $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0), 1.0);
            $outcome = $runner->run(
                new FastSuccessHandler(),
                JobEnvelope::make('stub.fast', []),
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
        });
    }

    /**
     * 验证：协程 sleep 超过 timeout 时标记失败并 REQUEUED（进入现有 retry 流程）。
     */
    public function testCoroutineSleepTimeoutRequeues(): void
    {
        $this->runInCoroutine(function (): void {
            $captured = null;
            $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 3, baseDelayMs: 10, jitterRatio: 0.0), 0.05);
            $outcome = $runner->run(
                new SleepyHandler(0.3),
                JobEnvelope::make('stub.slow', [], [], new JobRetryPolicy(maxAttempts: 3)),
                static function (JobEnvelope $next) use (&$captured): void {
                    $captured = $next;
                },
                static function (): void {
                    throw new \RuntimeException('should not dead on first timeout');
                },
            );

            $this->assertSame(JobRunOutcome::REQUEUED, $outcome);
            $this->assertInstanceOf(JobEnvelope::class, $captured);
            $this->assertSame(2, $captured->attempt);
        });
    }

    /**
     * 验证：超时且 attempt 已耗尽时走 DEAD（dead-letter），而非无限重试。
     */
    public function testTimeoutExhaustedGoesDead(): void
    {
        $this->runInCoroutine(function (): void {
            $deadError = null;
            $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 2, jitterRatio: 0.0), 0.05);
            $job = JobEnvelope::make('stub.slow', [], [], new JobRetryPolicy(maxAttempts: 2))
                ->withAttempt(2);
            $outcome = $runner->run(
                new SleepyHandler(0.3),
                $job,
                static function (): void {
                    throw new \RuntimeException('should not requeue when exhausted');
                },
                static function (JobEnvelope $j, string $error) use (&$deadError): void {
                    $deadError = $error;
                },
            );

            $this->assertSame(JobRunOutcome::DEAD, $outcome);
            $this->assertIsString($deadError);
            $this->assertStringContainsString('timed out', $deadError);
        });
    }

    /**
     * 验证：Handler 抛异常仍按可重试处理（与超时路径独立，回归既有语义）。
     */
    public function testHandlerExceptionStillRequeues(): void
    {
        $this->runInCoroutine(function (): void {
            $outcome = (new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0), 2.0))->run(
                new ThrowingHandler(),
                JobEnvelope::make('stub.throw', []),
                static function (): void {
                },
                static function (): void {
                    throw new \RuntimeException('should not dead');
                },
            );
            $this->assertSame(JobRunOutcome::REQUEUED, $outcome);
        });
    }
}

/** 立即成功的 Handler，用于超时预算内完成路径。 */
final class FastSuccessHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.fast'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        unset($job);

        return JobResult::success();
    }
}

/** 协程 sleep 超过超时预算，用于验证 TimeoutGuard 中断。 */
final class SleepyHandler implements JobHandlerInterface
{
    public function __construct(private readonly float $sleepSeconds)
    {
    }

    public function types(): array
    {
        return ['stub.slow'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        unset($job);
        Coroutine::sleep($this->sleepSeconds);

        return JobResult::success();
    }
}

/** 抛异常的 Handler，确认异常仍映射为 RETRY。 */
final class ThrowingHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.throw'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        unset($job);
        throw new \RuntimeException('boom');
    }
}
