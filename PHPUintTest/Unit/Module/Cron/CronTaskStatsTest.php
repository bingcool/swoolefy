<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ExecutionStatus;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskLogRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskStatsResultDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatsQueryDto;
use Test\Module\Cron\Response\CronTaskManager\CronTaskStatsResponse;

/**
 * taskStats DTO / 时间窗 / 执行详情读结构化列（不依赖 DB）。
 */
final class CronTaskStatsTest extends TestCase
{
    public function testEmptyAggregatedDtoHasAllZeroKeys(): void
    {
        $dto = CronTaskStatsResultDto::fromAggregated(9, ExecutionStatus::emptyCounts());
        $data = CronTaskStatsResponse::fromDto($dto)->getData();
        $this->assertSame(9, $data['taskId']);
        foreach (['total', 'pending', 'running', 'success', 'failed', 'skipped', 'timeout', 'cancelled', 'finished', 'attempted', 'samples'] as $key) {
            $this->assertSame(0, $data[$key], $key);
        }
        $this->assertSame(0.0, $data['successRate']);
        $this->assertSame(0.0, $data['avgDurationMs']);
        $this->assertSame(0.0, $data['maxDurationMs']);
    }

    public function testQueryTimeRangeIsHalfOpen(): void
    {
        $dto = TaskStatsQueryDto::of(1, '2026-08-01 00:00:00', '2026-08-02 00:00:00');
        $this->assertSame('2026-08-01 00:00:00', $dto->getStart());
        $this->assertSame('2026-08-02 00:00:00', $dto->getEnd());
        $this->assertNull(TaskStatsQueryDto::of(1, '  ', '')->getStart());
    }

    public function testDetailReadsStatusColumnNotMessage(): void
    {
        $dto = ExecutionDetailDto::fromLogRow([
            'cron_id' => 3,
            'exec_batch_id' => 'abc',
            'pid' => 12,
            'status' => ExecutionStatus::SUCCESS,
            'trigger_type' => ExecutionStatus::TRIGGER_SCHEDULER,
            'scheduled_at' => '2026-08-20 10:00:00',
            'started_at' => '2026-08-20 10:00:01',
            'finished_at' => '2026-08-20 10:00:02',
            'duration_ms' => 1500,
            'exit_code' => 0,
            'http_status' => null,
            'message' => '执行失败？',
            'task_item' => ['cron_name' => 't'],
        ]);
        $this->assertSame('success', $dto->getStatus());
        $this->assertSame(ExecutionStatus::SUCCESS, $dto->getStatusCode());
        $this->assertSame(1500.0, $dto->getDurationMs());
        $this->assertSame('执行失败？', $dto->getMessage());
        $this->assertSame(0, $dto->getExitCode());
    }

    public function testLogRowMapsStructuredFields(): void
    {
        $dto = CronTaskLogRowDto::fromEntityRow([
            'id' => 8,
            'cron_id' => 3,
            'exec_batch_id' => 'b1',
            'pid' => 99,
            'status' => ExecutionStatus::TIMEOUT,
            'trigger_type' => ExecutionStatus::TRIGGER_RUN_ONCE,
            'duration_ms' => 30000,
            'http_status' => 0,
            'message' => 'timeout',
            'created_at' => '2026-08-20 11:00:00',
        ]);
        $this->assertSame(ExecutionStatus::TIMEOUT, $dto->getStatus());
        $this->assertSame('timeout', $dto->getStatusName());
        $this->assertSame(ExecutionStatus::TRIGGER_RUN_ONCE, $dto->getTriggerType());
        $this->assertSame(30000, $dto->getDurationMs());
    }

    public function testTaskStatsServiceDoesNotScanMessage(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Test\Module\Cron\Service\CronTaskManagerService::class))->getFileName()
        );
        $this->assertStringContainsString("group('status')", $src);
        $this->assertStringContainsString('AVG(duration_ms)', $src);
        $this->assertStringContainsString('created_at', $src);
        $this->assertStringNotContainsString("strpos(\$message, '成功')", $src);
        $this->assertStringNotContainsString('extractDurationMs', $src);
        $this->assertStringNotContainsString("limit(0, 2000)", $src);
    }

    public function testSuccessRateDenominatorExcludesSkipped(): void
    {
        $dto = CronTaskStatsResultDto::fromAggregated(1, ExecutionStatus::aggregateCounts([
            ['status' => ExecutionStatus::SUCCESS, 'total' => 8],
            ['status' => ExecutionStatus::FAILED, 'total' => 2],
            ['status' => ExecutionStatus::SKIPPED, 'total' => 10],
        ]));
        $this->assertSame(10, $dto->getAttempted());
        $this->assertSame(20, $dto->getFinished());
        $this->assertSame(80.0, $dto->getSuccessRate());
    }
}
