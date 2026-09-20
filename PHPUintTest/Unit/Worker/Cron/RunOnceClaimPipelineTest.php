<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FrozenCronClock;
use PHPUintTest\Unit\Worker\Cron\Support\ManualCronTimer;
use PHPUintTest\Unit\Worker\Cron\Support\RecordingExecutor;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\CronRunOnceAlreadyClaimedException;
use Swoolefy\Worker\Cron\CronRunOnceClaimConst;
use Swoolefy\Worker\Cron\ExecutionResult;

/**
 * RunOnce Claim：UNIQUE 冲突 / 闸门必须挡住执行器。
 */
final class RunOnceClaimPipelineTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    public function testDuplicateInsertDeferDoesNotRunExecutor(): void
    {
        $executor = new RecordingExecutor();
        $manager = $this->manager(
            $executor,
            logWriter: static function (): void {
                throw new CronRunOnceAlreadyClaimedException(CronRunOnceClaimConst::DEFER);
            },
        );
        $manager->start();

        $result = $manager->runOnceNow('id:1', 1001);

        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $executor->commands);
    }

    public function testDuplicateInsertAckIsCompletedWithoutExecutor(): void
    {
        $executor = new RecordingExecutor();
        $manager = $this->manager(
            $executor,
            logWriter: static function (): void {
                throw new CronRunOnceAlreadyClaimedException(CronRunOnceClaimConst::ACK);
            },
        );
        $manager->start();

        $result = $manager->runOnceNow('id:1', 1001);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->isCompleted());
        $this->assertSame([], $executor->commands);
    }

    public function testClaimGateAckSkipsExecutorAndIsCompleted(): void
    {
        $executor = new RecordingExecutor();
        $writes = 0;
        $manager = $this->manager(
            $executor,
            logWriter: static function () use (&$writes): void {
                $writes++;
            },
            runOnceClaim: static fn (int $requestId): string => CronRunOnceClaimConst::ACK,
        );
        $manager->start();
        $writes = 0;

        $result = $manager->runOnceNow('id:1', 1001);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $executor->commands);
        $this->assertSame(0, $writes);
    }

    public function testClaimGateDeferSkipsExecutorAndDoesNotComplete(): void
    {
        $executor = new RecordingExecutor();
        $manager = $this->manager(
            $executor,
            runOnceClaim: static fn (int $requestId): string => CronRunOnceClaimConst::DEFER,
        );
        $manager->start();

        $result = $manager->runOnceNow('id:1', 1001);

        $this->assertTrue($result->isSkipped());
        $this->assertFalse($result->isCompleted());
        $this->assertSame([], $executor->commands);
    }

    public function testPollerAcksWhenClaimSaysAlreadyDone(): void
    {
        $acked = [];
        $rows = [$this->row(false)];
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
                $acked[] = [$requestId, $result->isSuccess()];
            },
            runOnceClaim: static fn (int $requestId): string => CronRunOnceClaimConst::ACK,
        );
        $manager->start();

        $rows = [$this->row(true, [1001])];
        $manager->syncFromFetcher();

        $this->assertSame([], $executor->commands);
        $this->assertSame([[1001, true]], $acked);
    }

    /**
     * @param null|callable(object,string,string,int,array):void $logWriter
     * @param null|callable(int):string $runOnceClaim
     */
    private function manager(
        RecordingExecutor $executor,
        ?callable $logWriter = null,
        ?callable $runOnceClaim = null,
    ): CronManager {
        return new CronManager(
            fetcher: fn (): array => [$this->row(false)],
            executor: $executor,
            timer: new ManualCronTimer(),
            clock: new FrozenCronClock(1000),
            pollIntervalMs: 0,
            logWriter: $logWriter,
            runOnceClaim: $runOnceClaim,
        );
    }

    /**
     * @param list<int> $requestIds
     * @return array<string, mixed>
     */
    private function row(bool $runOnce, array $requestIds = []): array
    {
        $row = [
            'id' => 1,
            'name' => 'job-1',
            'expression' => '15',
            'command' => 'now.sh',
            'exec_type' => 1,
            'status' => 1,
            'with_block_lapping' => 0,
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
