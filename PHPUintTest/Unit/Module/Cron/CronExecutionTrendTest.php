<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ExecutionStatus;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionTrendBucketDto;
use Test\Module\Cron\Service\CronTaskManagerService;

final class CronExecutionTrendTest extends TestCase
{
    public function testTrendSourceUsesNonEmptyBatchAndDistinctBatchCount(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );

        $this->assertStringContainsString("whereNotNull('exec_batch_id')", $src);
        $this->assertStringContainsString("where('exec_batch_id', '<>', '')", $src);
        $this->assertStringContainsString('COUNT(DISTINCT exec_batch_id) AS total', $src);
    }

    public function testTrendOnlyAggregatesFourTargetStatuses(): void
    {
        $service = (new \ReflectionClass(CronTaskManagerService::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(CronTaskManagerService::class))->getMethod('applyTrendStatusCount');
        $method->setAccessible(true);

        $bucket = ExecutionTrendBucketDto::of('10:00', 0, 0, 0, 0, 0);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::SUCCESS, 2);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::FAILED, 1);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::TIMEOUT, 3);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::SKIPPED, 4);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::CANCELLED, 99);
        $bucket = $method->invoke($service, $bucket, ExecutionStatus::RUNNING, 88);

        $data = $bucket->toDeepArray();
        $this->assertSame(2, $data['success']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(3, $data['timeout']);
        $this->assertSame(4, $data['skipped']);
        $this->assertSame(10, $data['total']);
    }

    public function testDashboardTodayTotalMatchesTrendLogicExcludingSkipped(): void
    {
        $service = (new \ReflectionClass(CronTaskManagerService::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(CronTaskManagerService::class))->getMethod('foldDashboardExecCounts');
        $method->setAccessible(true);

        $counts = $method->invoke($service, [
            ['status' => ExecutionStatus::SUCCESS, 'total' => 5],
            ['status' => ExecutionStatus::FAILED, 'total' => 2],
            ['status' => ExecutionStatus::TIMEOUT, 'total' => 1],
            ['status' => ExecutionStatus::SKIPPED, 'total' => 4],
        ]);

        $this->assertSame(8, $counts['today'], 'today = success+failed+timeout，不含 skipped');
        $this->assertSame(5, $counts['success']);
        $this->assertSame(2, $counts['failed']);
        $this->assertSame(1, $counts['timeout']);
        $this->assertSame(4, $counts['skipped']);
    }
}
