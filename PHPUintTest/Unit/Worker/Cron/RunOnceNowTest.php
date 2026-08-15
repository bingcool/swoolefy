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
use Swoolefy\Worker\Cron\ExpressionParser;

/**
 * CronManager::runOnceNow：忽略 expression 立刻执行一次，不改 nextRunAt / Timer。
 *
 * @see \Swoolefy\Worker\Cron\CronManager::runOnceNow()
 */
final class RunOnceNowTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    /**
     * 成功路径：执行当前定义一次，nextRunAt 与 Timer 保持不变。
     */
    public function testSuccessDoesNotChangeNextRunAtOrTimer(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $executor = new RecordingExecutor();
        $manager = $this->manager(fn (): array => [$this->row(1, '15', 1, 'now.sh')], $executor, $timer, $clock);
        $manager->start();

        $job = $manager->registry()->get('id:1');
        $this->assertNotNull($job);
        $nextBefore = $job->nextRunAt;
        $timerIdBefore = $job->timerId;
        $this->assertGreaterThan(1000, $nextBefore);
        $this->assertSame(1, $manager->timerCountFor('id:1'));

        $result = $manager->runOnceNow('id:1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['now.sh'], $executor->commands);
        $alive = $manager->registry()->get('id:1');
        $this->assertSame($nextBefore, $alive?->nextRunAt, 'runOnceNow 不得改写 nextRunAt');
        $this->assertSame($timerIdBefore, $alive?->timerId, '不得替换已有 one-shot Timer');
        $this->assertSame(1, $manager->timerCountFor('id:1'));
        $this->assertFalse($alive?->running);
        $this->assertSame(1000, $alive?->lastRunAt);
    }

    /**
     * 停用任务：FAILED，不调 Executor，Timer 仍为 0。
     */
    public function testDisabledJobFailsWithoutExecuting(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $executor = new RecordingExecutor();
        $manager = $this->manager(fn (): array => [$this->row(1, '15', 0, 'off.sh')], $executor, $timer, $clock);
        $manager->start();

        $result = $manager->runOnceNow('id:1');

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('已停用', $result->message);
        $this->assertSame([], $executor->commands);
        $this->assertSame(0, $manager->timerCountFor('id:1'));
        $this->assertNotNull($manager->registry()->get('id:1'));
    }

    /**
     * 缺失 / 已删除：FAILED，不上抛。
     */
    public function testMissingAndDeletedFailClearly(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $rows = [$this->row(1, '15', 1, 'a.sh')];
        $manager = $this->manager(function () use (&$rows) { return $rows; }, new RecordingExecutor(), $timer, $clock);
        $manager->start();

        $missing = $manager->runOnceNow('id:999');
        $this->assertTrue($missing->isFailed());
        $this->assertStringContainsString('不存在', $missing->message);

        $rows = [];
        $manager->syncFromFetcher();
        $deleted = $manager->runOnceNow('id:1');
        $this->assertTrue($deleted->isFailed());
        $this->assertStringContainsString('不存在', $deleted->message);
    }

    /**
     * with_block_lapping=1：执行中 runOnceNow 必须 SKIP，且不改 nextRunAt。
     */
    public function testOverlapSkipDoesNotStealTimer(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $once = null;
        $nextDuring = null;
        $timerIdDuring = null;
        $manager = null;
        $executor = new RecordingExecutor(function () use (&$manager, &$once, &$nextDuring, &$timerIdDuring): void {
            $job = $manager?->registry()->get('id:1');
            $nextDuring = $job?->nextRunAt;
            $timerIdDuring = $job?->timerId;
            $once = $manager?->runOnceNow('id:1');
        });
        $manager = $this->manager(
            fn (): array => [$this->row(1, '5', 1, 'sleep.sh', '2026-01-01', 1)],
            $executor,
            $timer,
            $clock,
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $this->assertInstanceOf(ExecutionResult::class, $once);
        $this->assertTrue($once->isSkipped(), '重叠必须 SKIPPED');
        $this->assertStringContainsString('with_block_lapping', $once->message);
        $this->assertCount(1, $executor->snapshots, '重叠不得进入 Executor');
        $alive = $manager->registry()->get('id:1');
        $this->assertSame($nextDuring, $alive?->nextRunAt);
        $this->assertSame($timerIdDuring, $alive?->timerId);
        $this->assertSame(1, $manager->timerCountFor('id:1'));
    }

    /**
     * cron_skip 命中：runOnceNow 仍 SKIP（忽略 expression，不忽略时间窗）。
     */
    public function testTimeWindowStillApplies(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(strtotime('2026-08-15 10:00:00'));
        $executor = new RecordingExecutor();
        $manager = $this->manager(
            fn (): array => [[
                'id' => 1,
                'name' => 'windowed',
                'expression' => '15',
                'command' => 'x.sh',
                'exec_type' => 1,
                'status' => 1,
                'cron_skip' => [['10:00:00', '10:00:30']],
            ]],
            $executor,
            $timer,
            $clock,
        );
        $manager->start();
        $nextBefore = $manager->registry()->get('id:1')?->nextRunAt;

        $result = $manager->runOnceNow('id:1');

        $this->assertTrue($result->isSkipped());
        $this->assertStringContainsString('cron_skip', $result->message);
        $this->assertSame([], $executor->commands);
        $this->assertSame($nextBefore, $manager->registry()->get('id:1')?->nextRunAt);
    }

    /**
     * retry 仍作用于这一次：retry=1 时首次 FAILED 再成功。
     */
    public function testRetryStillApplies(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $executor = new class implements \Swoolefy\Worker\Cron\CronExecutorInterface {
            public int $n = 0;

            public function run(\Swoolefy\Worker\Cron\ExecutionSnapshot $snapshot): ExecutionResult
            {
                ++$this->n;
                if ($this->n === 1) {
                    return ExecutionResult::failed('transient');
                }

                return ExecutionResult::success('recovered');
            }
        };
        $manager = new CronManager(
            fetcher: fn (): array => [$this->row(1, '15', 1, 'retry.sh') + ['retry' => 1]],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
        $manager->start();
        $nextBefore = $manager->registry()->get('id:1')?->nextRunAt;

        $result = $manager->runOnceNow('id:1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $executor->n);
        $this->assertSame($nextBefore, $manager->registry()->get('id:1')?->nextRunAt);
        $this->assertSame(1, $manager->timerCountFor('id:1'));
    }

    /**
     * calculateNextRunAt 只算下一合法点，不回填历史；runOnceNow 也不触发补跑。
     */
    public function testCalculateNextRunAtStillNoBackfillAfterRunOnceNow(): void
    {
        $parser = new ExpressionParser();
        $interval = $parser->parse(15);
        $this->assertSame(1050, $interval->calculateNextRunAt(1040), '错过 1005/1020/1035 只给下一格');
        $this->assertSame(1005, $interval->calculateNextRunAt(1000));

        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $executor = new RecordingExecutor();
        $manager = $this->manager(fn (): array => [$this->row(1, '15', 1, 'late.sh')], $executor, $timer, $clock);
        $manager->start();
        $armed = $manager->registry()->get('id:1')?->nextRunAt;
        $this->assertSame(1005, $armed);

        $clock->set(1040);
        $result = $manager->runOnceNow('id:1');
        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $executor->snapshots, '不得把错过的历史点补跑出来');
        $this->assertSame(1005, $manager->registry()->get('id:1')?->nextRunAt, '已武装的下一计划点不变');
        $this->assertSame(1050, $interval->calculateNextRunAt(1040));
    }

    /**
     * @param callable():list<array<string,mixed>> $fetcher
     */
    private function manager(
        callable $fetcher,
        \Swoolefy\Worker\Cron\CronExecutorInterface $executor,
        ManualCronTimer $timer,
        FrozenCronClock $clock,
    ): CronManager {
        return new CronManager(
            fetcher: $fetcher,
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        string $expression,
        int $status,
        string $command,
        string $updatedAt = '2026-01-01',
        int $block = 0,
    ): array {
        return [
            'id' => $id,
            'name' => 'job-' . $id,
            'expression' => $expression,
            'command' => $command,
            'exec_type' => 1,
            'status' => $status,
            'with_block_lapping' => $block,
            'updated_at' => $updatedAt,
        ];
    }
}
