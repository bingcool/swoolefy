<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Support;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoolefy\Support\ConsumeRetryLimit;

/**
 * 重试退避等待必须可被停机标志打断：等待分片粒度决定优雅退出的额外延迟。
 */
final class ConsumeRetryLimitWaitTest extends CoroutineTestCase
{
    public function testWaitSleepsFullDelayWithoutStop(): void
    {
        $this->runInCoroutine(function (): void {
            $budget = $this->budget(0.3);
            $budget->fail();

            $startedAt = microtime(true);
            $budget->wait(static fn (): bool => false);

            $this->assertGreaterThanOrEqual(0.3, microtime(true) - $startedAt);
        });
    }

    public function testStopFlagEndsWaitEarly(): void
    {
        $this->runInCoroutine(function (): void {
            $budget = $this->budget(3.0);
            $budget->fail();

            $stop = false;
            Coroutine::create(static function () use (&$stop): void {
                Coroutine::sleep(0.2);
                $stop = true;
            });

            $startedAt = microtime(true);
            $budget->wait(static function () use (&$stop): bool {
                return $stop;
            });

            $elapsed = microtime(true) - $startedAt;
            $this->assertGreaterThanOrEqual(0.2, $elapsed);
            $this->assertLessThan(1.0, $elapsed, 'wait must end within the 0.2s slice after stop');
        });
    }

    public function testZeroDelayReturnsImmediately(): void
    {
        $this->runInCoroutine(function (): void {
            $budget = $this->budget(0.0);
            $budget->fail();

            $startedAt = microtime(true);
            $budget->wait(static fn (): bool => false);

            $this->assertLessThan(0.1, microtime(true) - $startedAt);
        });
    }

    private function budget(float $baseSeconds): object
    {
        return new class($baseSeconds) {
            use ConsumeRetryLimit;

            public function __construct(float $baseSeconds)
            {
                $this->maxRetryAttempts = 0;
                $this->retryBackoffBaseSeconds = $baseSeconds;
            }

            public function fail(): bool
            {
                return $this->markConsumeFailure();
            }

            /**
             * @param callable(): bool $shouldStop
             */
            public function wait(callable $shouldStop): void
            {
                $this->waitConsumeRetry($shouldStop);
            }
        };
    }
}
