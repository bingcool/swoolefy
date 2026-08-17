<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FrozenCronClock;
use PHPUintTest\Unit\Worker\Cron\Support\ManualCronTimer;
use PHPUintTest\Unit\Worker\Cron\Support\RecordingExecutor;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\ExecutionResult;

/**
 * Polling 消费 run_once_requested：调用 runOnceNow 并 ack，不改 nextRunAt。
 */
final class RunOnceRequestedPollerTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    public function testPollerConsumesFlagAndAcks(): void
    {
        $acked = [];
        $rows = [$this->row(1, false)];
        $executor = new RecordingExecutor();
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $manager = new CronManager(
            fetcher: static function () use (&$rows): array {
                return $rows;
            },
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            runOnceAck: static function (string $jobId, int $cronTaskId, ExecutionResult $result) use (&$acked): void {
                $acked[] = [$jobId, $cronTaskId, $result->isSuccess()];
            },
        );
        $manager->start();
        $nextBefore = $manager->registry()->get('id:1')?->nextRunAt;

        $rows = [$this->row(1, true)];
        $manager->syncFromFetcher();

        $this->assertSame(['now.sh'], $executor->commands);
        $this->assertSame([['id:1', 1, true]], $acked);
        $this->assertSame($nextBefore, $manager->registry()->get('id:1')?->nextRunAt);
    }

    public function testNoFlagDoesNotRunOnce(): void
    {
        $acked = [];
        $executor = new RecordingExecutor();
        $manager = new CronManager(
            fetcher: fn (): array => [$this->row(1, false)],
            executor: $executor,
            timer: new ManualCronTimer(),
            clock: new FrozenCronClock(1000),
            pollIntervalMs: 0,
            runOnceAck: static function () use (&$acked): void {
                $acked[] = 1;
            },
        );
        $manager->start();
        $this->assertSame([], $executor->commands);
        $this->assertSame([], $acked);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, bool $runOnce): array
    {
        return [
            'id' => $id,
            'name' => 'job-' . $id,
            'expression' => '15',
            'command' => 'now.sh',
            'exec_type' => 1,
            'status' => 1,
            'with_block_lapping' => 0,
            'updated_at' => '2026-01-01',
            'run_once_requested' => $runOnce,
        ];
    }
}
