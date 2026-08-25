<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Request\CronTaskManager\TaskLogsQueryRequest;

final class TaskLogsQueryRequestTest extends TestCase
{
    public function testStartTimePrefersCanonicalField(): void
    {
        $request = (new TaskLogsQueryRequest())
            ->setStartTime(' 2026-08-25 09:00:00 ')
            ->setCreatedStart('2026-08-25 08:00:00');

        $this->assertSame('2026-08-25 09:00:00', $request->getStartTime());
    }

    public function testStartTimeFallsBackToCreatedStartAlias(): void
    {
        $request = (new TaskLogsQueryRequest())
            ->setStartTime('  ')
            ->setCreatedStart(' 2026-08-25 08:00:00 ');

        $this->assertSame('2026-08-25 08:00:00', $request->getStartTime());
    }

    public function testEndTimeFallsBackToCreatedEndAlias(): void
    {
        $request = (new TaskLogsQueryRequest())
            ->setEndTime('')
            ->setCreatedEnd(' 2026-08-25 10:00:00 ');

        $this->assertSame('2026-08-25 10:00:00', $request->getEndTime());
    }
}
