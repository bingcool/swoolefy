<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\CronNodeLiveness;

/**
 * 节点存活公式：online iff last_heartbeat_at 非空且 age <= max(3*interval, interval+5)。
 */
final class CronNodeLivenessTest extends TestCase
{
    public function testFreshHeartbeatIsOnline(): void
    {
        $now = 1_000_000;
        $this->assertSame(
            CronNodeLiveness::STATUS_ONLINE,
            CronNodeLiveness::status($now, $now - 1, 15),
        );
    }

    public function testStaleHeartbeatIsOffline(): void
    {
        $now = 1_000_000;
        $interval = 15;
        $staleAfter = CronNodeLiveness::staleAfterSeconds($interval);
        $this->assertSame(45, $staleAfter);
        $this->assertSame(
            CronNodeLiveness::STATUS_OFFLINE,
            CronNodeLiveness::status($now, $now - $staleAfter - 1, $interval),
        );
    }

    public function testNullLastHeartbeatIsOffline(): void
    {
        $this->assertSame(
            CronNodeLiveness::STATUS_OFFLINE,
            CronNodeLiveness::status(time(), null, 15),
        );
        $this->assertNull(CronNodeLiveness::parseHeartbeatAt(''));
        $this->assertNull(CronNodeLiveness::parseHeartbeatAt(null));
        $this->assertNull(CronNodeLiveness::parseHeartbeatAt(0));
    }

    public function testExactBoundaryIsOnline(): void
    {
        $now = 1_000_000;
        $interval = 15;
        $staleAfter = CronNodeLiveness::staleAfterSeconds($interval);
        $this->assertSame(
            CronNodeLiveness::STATUS_ONLINE,
            CronNodeLiveness::status($now, $now - $staleAfter, $interval),
            'age == staleAfter 仍为 online（闭区间）',
        );
        $this->assertSame(
            CronNodeLiveness::STATUS_OFFLINE,
            CronNodeLiveness::status($now, $now - $staleAfter - 1, $interval),
        );
    }

    public function testConfIntervalChangesStaleAfter(): void
    {
        $this->assertSame(45, CronNodeLiveness::staleAfterSeconds(15));
        $this->assertSame(30, CronNodeLiveness::staleAfterSeconds(10));
        $this->assertSame(6, CronNodeLiveness::staleAfterSeconds(1));
        $this->assertSame(45, CronNodeLiveness::staleAfterSeconds(0), '非法间隔回退默认 15');

        $now = 1_000_000;
        $this->assertSame(
            CronNodeLiveness::STATUS_ONLINE,
            CronNodeLiveness::status($now, $now - 30, 10),
        );
        $this->assertSame(
            CronNodeLiveness::STATUS_OFFLINE,
            CronNodeLiveness::status($now, $now - 31, 10),
        );
        $this->assertSame(
            CronNodeLiveness::STATUS_ONLINE,
            CronNodeLiveness::status($now, $now - 31, 15),
            '默认 15s 间隔阈值 45，31s 仍 online；对比 interval=10 的 31s offline',
        );
    }

    public function testParseDatetimeAndUnix(): void
    {
        $ts = 1_700_000_000;
        $this->assertSame($ts, CronNodeLiveness::parseHeartbeatAt($ts));
        $this->assertSame($ts, CronNodeLiveness::parseHeartbeatAt((string) $ts));
        $this->assertSame(
            strtotime('2026-08-20 01:00:00'),
            CronNodeLiveness::parseHeartbeatAt('2026-08-20 01:00:00'),
        );
    }
}
