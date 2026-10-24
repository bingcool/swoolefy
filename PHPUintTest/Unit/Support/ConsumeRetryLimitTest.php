<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support;

use PHPUintTest\TestCase;
use Swoolefy\Support\ConsumeRetryLimit;

/**
 * 消费失败重试预算的共享计数与退避算法（Kafka / AMQP handler 共用）。
 */
final class ConsumeRetryLimitTest extends TestCase
{
    public function testRetriesUntilLimitThenExhausted(): void
    {
        $budget = $this->budget(3);

        $this->assertFalse($budget->fail());
        $this->assertFalse($budget->fail());
        $this->assertTrue($budget->fail());
        $this->assertSame(3, $budget->attempts());
    }

    /**
     * 默认预算：5 次处理机会，前 4 次的退避 1+2+4+8 秒构成约 15 秒的下游抖动容忍窗口。
     */
    public function testDefaultLimitGivesFiveAttempts(): void
    {
        $budget = $this->budget();

        $this->assertFalse($budget->fail());
        $this->assertFalse($budget->fail());
        $this->assertFalse($budget->fail());
        $this->assertFalse($budget->fail());
        $this->assertTrue($budget->fail());
        $this->assertSame(5, $budget->attempts());
    }

    public function testResetRestoresBudget(): void
    {
        $budget = $this->budget(2);

        $this->assertFalse($budget->fail());
        $budget->reset();
        $this->assertFalse($budget->fail());
        $this->assertTrue($budget->fail());
    }

    public function testZeroOrNegativeLimitMeansUnlimited(): void
    {
        $unlimited = $this->budget(0);

        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($unlimited->fail());
        }

        $this->assertSame(50, $unlimited->attempts());
    }

    public function testDelayDoublesFromBaseAndIsCapped(): void
    {
        $budget = $this->budget(9, 1.0, 15.0);

        $budget->fail();
        $this->assertSame(1.0, $budget->delay());
        $budget->fail();
        $this->assertSame(2.0, $budget->delay());
        $budget->fail();
        $this->assertSame(4.0, $budget->delay());
        $budget->fail();
        $this->assertSame(8.0, $budget->delay());
        $budget->fail();
        $this->assertSame(15.0, $budget->delay());
        $budget->fail();
        $budget->fail();
        $this->assertSame(15.0, $budget->delay());
    }

    public function testResetRewindsDelayToBase(): void
    {
        $budget = $this->budget(9, 1.0, 15.0);

        $budget->fail();
        $budget->fail();
        $this->assertSame(2.0, $budget->delay());
        $budget->reset();
        $budget->fail();
        $this->assertSame(1.0, $budget->delay());
    }

    /**
     * 基数为 0 表示紧密重试；上限小于基数时按基数等待，避免退化成 0 之外还要更短的等待。
     */
    public function testZeroBaseMeansNoWaiting(): void
    {
        $budget = $this->budget(3, 0.0, 15.0);
        $budget->fail();
        $this->assertSame(0.0, $budget->delay());

        $belowBase = $this->budget(3, 2.0, 1.0);
        $belowBase->fail();
        $this->assertSame(2.0, $belowBase->delay());
    }

    private function budget(?int $limit = null, float $base = 1.0, float $cap = 15.0): object
    {
        return new class($limit, $base, $cap) {
            use ConsumeRetryLimit;

            public function __construct(?int $limit, float $base, float $cap)
            {
                if ($limit !== null) {
                    $this->maxRetryAttempts = $limit;
                }
                $this->retryBackoffBaseSeconds = $base;
                $this->retryBackoffMaxSeconds = $cap;
            }

            public function fail(): bool
            {
                return $this->markConsumeFailure();
            }

            public function reset(): void
            {
                $this->resetConsumeFailures();
            }

            public function attempts(): int
            {
                return $this->consumeFailedAttempts();
            }

            public function delay(): float
            {
                return $this->consumeRetryDelaySeconds();
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
