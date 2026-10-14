<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Rocketmq;

use PHPUintTest\TestCase;
use Swoolefy\Support\Rocketmq\RocketmqConsumeSession;

final class RocketmqConsumeSessionTest extends TestCase
{
    public function testInvisibleMsIsRaisedToProxyMinimum(): void
    {
        $session = new RocketmqConsumeSession('t', 'g', ['*'], 8, 6000);

        $this->assertSame(RocketmqConsumeSession::MIN_INVISIBLE_MS, $session->effectiveInvisibleMs());
    }

    public function testInvisibleMsIsCappedAtMaximum(): void
    {
        $session = new RocketmqConsumeSession('t', 'g', ['*'], 8, 900000);

        $this->assertSame(RocketmqConsumeSession::MAX_INVISIBLE_MS, $session->effectiveInvisibleMs());
    }

    public function testTagExpressionFromArray(): void
    {
        $this->assertSame('paid || cancel', RocketmqConsumeSession::tagExpression(['paid', 'cancel']));
        $this->assertSame('paid', RocketmqConsumeSession::tagExpression([' paid ', '', 'paid']));
        $this->assertSame('*', RocketmqConsumeSession::tagExpression(['paid', '*']));
        $this->assertSame('*', RocketmqConsumeSession::tagExpression([]));
        $this->assertSame('*', RocketmqConsumeSession::tagExpression(['', ' ']));
    }

    public function testTagExpressionRejectsInvalidItems(): void
    {
        foreach ([['a||b'], [1]] as $tags) {
            try {
                RocketmqConsumeSession::tagExpression($tags);
                $this->fail('expected rejection: ' . json_encode($tags));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSidecarStartupWaitIsClampedToZeroAndFiveSeconds(): void
    {
        $this->assertSame(2.0, RocketmqConsumeSession::sidecarStartupWaitSeconds(2.0));
        $this->assertSame(5.0, RocketmqConsumeSession::sidecarStartupWaitSeconds(8.0));
        $this->assertSame(0.0, RocketmqConsumeSession::sidecarStartupWaitSeconds(-1.0));
    }

    public function testDeadlineUsesEffectiveInvisibleMs(): void
    {
        $session = new RocketmqConsumeSession('t', 'g', ['*'], 8, 6000);

        $this->assertSame(8000 * 1_000_000, $session->handleDeadlineNs(0, 2000));
    }
}
