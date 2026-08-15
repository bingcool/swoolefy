<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FrozenCronClock;
use PHPUintTest\Unit\Worker\Cron\Support\ManualCronTimer;
use PHPUintTest\Unit\Worker\Cron\Support\RecordingExecutor;
use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Worker\Cron\ConfigDiff;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\CronMetrics;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionSnapshot;
use Swoolefy\Worker\Cron\HttpExecutor;
use Swoolefy\Worker\Cron\ShellExecutor;
use Swoolefy\Worker\Cron\TaskDefinition;

/**
 * CronManager 同轮 retry：retry=0 不额外跑；retry=N 最多 1+N 次；
 * 只重试 FAILED；SKIPPED 不进 Executor；同一 Snapshot；指标按一轮记一次。
 *
 * @see \Swoolefy\Worker\Cron\CronManager::runWithRetry()
 */
final class RetryTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    /**
     * 缺省 / 非法 retry 规范化为 0；maxAttempts = 1+retry。
     */
    public function testRetryDefaultAndMaxAttempts(): void
    {
        $none = TaskDefinition::fromArray($this->row(1, 'x.sh'));
        $this->assertSame(0, $none->retry);
        $this->assertSame(1, $none->maxAttempts());

        $two = TaskDefinition::fromArray($this->row(1, 'x.sh', 2));
        $this->assertSame(2, $two->retry);
        $this->assertSame(3, $two->maxAttempts());

        $neg = TaskDefinition::fromArray($this->row(1, 'x.sh') + ['retry' => -3]);
        $this->assertSame(0, $neg->retry);
        $this->assertSame(1, $neg->maxAttempts());
    }

    /**
     * 改 retry 计入 fingerprint，ConfigDiff 产出 UPDATE。
     */
    public function testRetryChangeUpdatesFingerprint(): void
    {
        $zero = TaskDefinition::fromArray($this->row(1, 'x.sh', 0));
        $two = TaskDefinition::fromArray($this->row(1, 'x.sh', 2));
        $this->assertNotSame($zero->fingerprint(), $two->fingerprint());

        $ops = (new ConfigDiff())->diff(['id:1' => $zero], ['id:1' => $two]);
        $this->assertSame([ConfigDiff::UPDATE], array_column($ops, 'op'));
    }

    /**
     * retry=0：失败只跑 1 次，不额外 attempt。
     */
    public function testRetryZeroDoesNotRerun(): void
    {
        $executor = new RecordingExecutor(fail: true);
        $manager = $this->trigger($executor, 0);
        $this->assertCount(1, $executor->snapshots);
        $this->assertSame(1, $manager->timerCountFor('id:1'), 'retry 不得另武装 Timer');
    }

    /**
     * retry=2 + Shell：前两次 FAILED，第三次 SUCCESS；同一 Snapshot。
     */
    public function testRetryTwoShellFailThenSuccess(): void
    {
        $n = 0;
        /** @var list<ExecutionSnapshot> $seen */
        $seen = [];
        $running = [];
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $manager = new CronManager(
            fetcher: fn (): array => [$this->row(1, 'x.sh', 2)],
            executor: new ShellExecutor(function (ExecutionSnapshot $snapshot) use (&$n, &$seen, &$running, &$manager): ExecutionResult {
                $seen[] = $snapshot;
                $running[] = $manager->registry()->get('id:1')?->running;
                ++$n;
                if ($n < 3) {
                    return ExecutionResult::failed('shell transient', 0, 1);
                }

                return ExecutionResult::success('shell ok');
            }),
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $this->assertSame(3, $n, 'retry=2 → 最多 3 次，第 3 次成功');
        $this->assertSame($seen[0], $seen[1]);
        $this->assertSame($seen[0], $seen[2]);
        $this->assertCount(1, array_unique(array_map(static fn (ExecutionSnapshot $s): string => $s->execBatchId, $seen)));
        $this->assertSame([true, true, true], $running, 'Guard 在整个 attempt 序列期间保持 running');
        $this->assertFalse($manager->registry()->get('id:1')?->running);
        $this->assertSame(1, $manager->timerCountFor('id:1'));
    }

    /**
     * retry=2 + HTTP：前两次 FAILED，第三次 SUCCESS；同一 Snapshot。
     */
    public function testRetryTwoHttpFailThenSuccess(): void
    {
        $n = 0;
        /** @var list<ExecutionSnapshot> $seen */
        $seen = [];
        $http = new HttpExecutor(function (ExecutionSnapshot $snapshot) use (&$n, &$seen): array {
            $seen[] = $snapshot;
            ++$n;
            if ($n < 3) {
                return ['status' => 500, 'body' => 'err'];
            }

            return ['status' => 200, 'body' => 'ok'];
        });
        $this->trigger($http, 2, execType: 2, command: 'http://example.test/retry');

        $this->assertSame(3, $n);
        $this->assertSame($seen[0], $seen[1]);
        $this->assertSame($seen[0]->definition, $seen[2]->definition);
        $this->assertSame($seen[0]->execBatchId, $seen[2]->execBatchId);
    }

    /**
     * retry=2 三次都失败 → 本轮最终 FAILED；指标只加 1 次 failed。
     */
    public function testAllAttemptsFailRecordsOnce(): void
    {
        RuntimeRegistry::initialize(['metrics' => ['enable' => true], 'diagnostics' => ['enable' => true]]);
        $executor = new RecordingExecutor(fail: true);
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $manager = new CronManager(
            fetcher: fn (): array => [$this->row(1, 'fail.sh', 2)],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            metrics: new CronMetrics(RuntimeRegistry::metrics()),
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $this->assertCount(3, $executor->snapshots, 'retry=2 用尽 3 次仍 FAILED');
        $snapshot = RuntimeRegistry::metrics()?->snapshot();
        $this->assertSame(1, $snapshot['counter'][RuntimeMetrics::CRON_RUNS_FAILED] ?? 0);
        $this->assertSame(1, $snapshot['counter'][RuntimeMetrics::CRON_RUNS_TOTAL] ?? 0);
        $this->assertSame(0, $snapshot['counter'][RuntimeMetrics::CRON_RUNS_SUCCESS] ?? 0);
        $duration = $snapshot['histogram'][RuntimeMetrics::CRON_EXECUTION_DURATION] ?? null;
        $this->assertIsArray($duration);
        $this->assertSame(1, $duration['count'] ?? 0, 'duration 按一轮观察一次（含全部 attempt）');
    }

    /**
     * 时间窗 SKIP 不调用 Executor，因此也不会 retry。
     */
    public function testSkippedIsNotRetried(): void
    {
        $executor = new RecordingExecutor(fail: true);
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(strtotime('2026-08-15 10:00:00'));
        $manager = new CronManager(
            fetcher: fn (): array => [[
                'id' => 1,
                'name' => 'skipped',
                'expression' => '5',
                'command' => 'x.sh',
                'exec_type' => 1,
                'status' => 1,
                'retry' => 2,
                'cron_skip' => [['10:00:00', '10:00:30']],
            ]],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
        $manager->start();
        $clock->set(strtotime('2026-08-15 10:00:05'));
        $timer->advance(5000);
        $this->assertSame([], $executor->snapshots, 'SKIPPED 不得进入 Executor / retry');
    }

    /**
     * 重叠 SKIP 同样不 retry。
     */
    public function testOverlapSkipIsNotRetried(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $executor = new RecordingExecutor(function () use ($timer, $clock): void {
            $clock->advance(5);
            $timer->advance(5000);
        }, fail: true);
        $manager = new CronManager(
            fetcher: fn (): array => [$this->row(1, 'sleep.sh', 2) + ['with_block_lapping' => 1]],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);
        $this->assertCount(3, $executor->snapshots, '仅第一轮 1+retry 次；重叠 SKIP 不进 Executor');
    }

    /**
     * Executor 抛异常隔离为 FAILED 后仍可按 retry 再试，不得拖垮 Worker。
     */
    public function testIsolatedExceptionCanRetryThenSucceed(): void
    {
        $executor = new class implements \Swoolefy\Worker\Cron\CronExecutorInterface {
            public array $snapshots = [];
            public int $n = 0;

            public function run(ExecutionSnapshot $snapshot): ExecutionResult
            {
                $this->snapshots[] = $snapshot;
                ++$this->n;
                if ($this->n < 2) {
                    throw new \RuntimeException('boom attempt 1');
                }

                return ExecutionResult::success('recovered');
            }
        };
        $this->trigger($executor, 2);
        $this->assertSame(2, $executor->n);
        $this->assertSame($executor->snapshots[0], $executor->snapshots[1]);
    }

    /**
     * 推进到首个计划点并触发一轮。
     */
    private function trigger(
        \Swoolefy\Worker\Cron\CronExecutorInterface $executor,
        int $retry,
        int $execType = 1,
        string $command = 'x.sh',
    ): CronManager {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $row = $this->row(1, $command, $retry);
        $row['exec_type'] = $execType;
        if ($execType === 2) {
            $row['url'] = $command;
        }
        $manager = new CronManager(
            fetcher: fn (): array => [$row],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        return $manager;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $command, int $retry = 0): array
    {
        return [
            'id' => $id,
            'name' => 'job-' . $id,
            'expression' => '5',
            'command' => $command,
            'exec_type' => 1,
            'status' => 1,
            'retry' => $retry,
            'updated_at' => '2026-01-01',
        ];
    }
}
