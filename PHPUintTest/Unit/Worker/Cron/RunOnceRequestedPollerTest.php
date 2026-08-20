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
     * P0-1：enqueue A/B/C 后一轮 Polling 必须 Execution=3 且按 requestId 各 ack 一次。
     * 禁止 Execution=1 却 Consumed=3。
     */
    public function testThreePendingRequestsProduceThreeExecutionsAndThreeAcks(): void
    {
        $acked = [];
        $rows = [$this->row(1, false)];
        $executor = new RecordingExecutor();
        $manager = new CronManager(
            fetcher: static function () use (&$rows): array {
                return $rows;
            },
            executor: $executor,
            timer: new ManualCronTimer(),
            clock: new FrozenCronClock(1000),
            pollIntervalMs: 0,
            runOnceAck: static function (string $jobId, int $cronTaskId, ExecutionResult $result, int $requestId = 0) use (&$acked): void {
                $acked[] = [$jobId, $cronTaskId, $result->isSuccess(), $requestId];
            },
        );
        $manager->start();

        $rows = [$this->row(1, true, [11, 12, 13])];
        $manager->syncFromFetcher();

        $this->assertCount(3, $executor->commands, '三条 request 必须三次 Execution');
        $this->assertSame(['now.sh', 'now.sh', 'now.sh'], $executor->commands);
        $this->assertCount(3, $acked, '必须按 requestId 各 ack 一次，不得一次清掉全部');
        $this->assertSame([11, 12, 13], array_column($acked, 3));
        $this->assertTrue($acked[0][2] && $acked[1][2] && $acked[2][2]);
    }

    /**
     * P0-1：SKIPPED（重叠）不得 ack，请求留给下一轮。
     */
    public function testSkippedRunOnceDoesNotAck(): void
    {
        $acked = [];
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $rows = [$this->row(1, false, [], 1)];
        $rows[0]['expression'] = '5';
        $manager = null;
        $executor = new RecordingExecutor(function () use (&$manager, &$rows): void {
            $rows[0]['run_once_requested'] = true;
            $rows[0]['run_once_request_ids'] = [21];
            $rows[0]['run_once_request_id'] = 21;
            $manager?->syncFromFetcher();
        });
        $manager = new CronManager(
            fetcher: static function () use (&$rows): array {
                return $rows;
            },
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            runOnceAck: static function (string $jobId, int $cronTaskId, ExecutionResult $result, int $requestId = 0) use (&$acked): void {
                $acked[] = [$requestId, $result->status];
            },
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $this->assertCount(1, $executor->snapshots, '重叠的 runOnce 必须 SKIP 而不是进入 Executor');
        $this->assertSame([], $acked, 'SKIPPED 不得 ack，请求留给下一轮 Polling');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, bool $runOnce, array $requestIds = [], int $block = 0): array
    {
        $row = [
            'id' => $id,
            'name' => 'job-' . $id,
            'expression' => '15',
            'command' => 'now.sh',
            'exec_type' => 1,
            'status' => 1,
            'with_block_lapping' => $block,
            'updated_at' => '2026-01-01',
            'run_once_requested' => $runOnce,
        ];
        if ($requestIds !== []) {
            $row['run_once_request_ids'] = $requestIds;
            $row['run_once_request_id'] = $requestIds[0];
        }

        return $row;
    }
}
