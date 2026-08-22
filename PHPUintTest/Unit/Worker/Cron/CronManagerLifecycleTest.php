<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FrozenCronClock;
use PHPUintTest\Unit\Worker\Cron\Support\ManualCronTimer;
use PHPUintTest\Unit\Worker\Cron\Support\RecordingExecutor;
use PHPUintTest\Unit\Worker\Cron\Support\ThrowingAfterCronTimer;
use Swoolefy\Core\Runtime\Metrics\MetricsRegistry;
use Swoolefy\Core\Runtime\Metrics\RuntimeMetrics;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\CronMetrics;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionSnapshot;
use Swoolefy\Worker\Cron\ExecutionStatus;

/**
 * CronManager 生命周期与不变量（文档 P0/P1）。
 *
 * 使用 ManualCronTimer + FrozenCronClock，不依赖真实 Swoole。
 * ManualCronTimer 同步触发 onTrigger，便于断言 Guard / Snapshot。
 * 生产 SwooleCronTimer 必须把 after/tick 投进协程（见 Coroutine 套件）。
 * 覆盖：Timer 数量、Config Sync、重叠 Guard、DB 故障 Last Known Good、
 * ExecutionSnapshot 冻结、Worker Stop/Restart、Job 异常隔离、nodeId、时间窗指标。
 *
 * @see \Swoolefy\Worker\Cron\CronManager
 */
final class CronManagerLifecycleTest extends TestCase
{
    /**
     * 每个用例后复位 RuntimeRegistry，避免 cron snapshot / metrics 泄漏到下一测。
     */
    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    /**
     * 生产 Timer 必须走 Tick 封装（afterTimer/tickTimer 已 goApp），禁止裸 Swoole\Timer。
     * 真实调度行为由 Coroutine 套件 SwooleCronTimerCoroutineTest 覆盖。
     */
    public function testProductionTimerUsesTickWrapper(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Swoolefy\Worker\Cron\SwooleCronTimer::class))->getFileName()
        );
        $this->assertStringContainsString('Tick::afterTimer', $src, 'Job one-shot 必须走 Tick::afterTimer（内含 goApp）');
        $this->assertStringContainsString('Tick::tickTimer', $src, 'Polling 必须走 Tick::tickTimer（内含 goApp）');
        $this->assertStringContainsString('Tick::delTicker', $src, 'clear 必须走 Tick::delTicker');
        $this->assertStringNotContainsString('\\Swoole\\Timer::after', $src, '禁止裸 Swoole\\Timer::after');
        $this->assertStringNotContainsString('\\Swoole\\Timer::tick', $src, '禁止裸 Swoole\\Timer::tick');
        $this->assertStringNotContainsString('\\Swoole\\Timer::clear', $src, '禁止裸 Swoole\\Timer::clear');
    }

    /**
     * ADD/UPDATE/DISABLE/ENABLE/DELETE 后 Active Job 的 Timer 数必须为 0 或 1。
     * ENABLE 不得立刻执行（Enable ≠ Immediately Run）。
     */
    public function testAddUpdateDeleteEnableDisableTimerInvariant(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $rows = [$this->row(1, '15', 1, 'a.sh')];
        $manager = $this->manager(function () use (&$rows) { return $rows; }, new RecordingExecutor(), $timer, $clock);
        $manager->start();

        $this->assertSame(1, $manager->timerCountFor('id:1'), 'ADD → 1 Timer');

        $rows = [$this->row(1, '20', 1, 'a.sh', '2026-01-02')];
        $manager->syncFromFetcher();
        $this->assertSame(1, $manager->timerCountFor('id:1'), 'UPDATE → 仍 1 Timer');

        $rows = [$this->row(1, '20', 0, 'a.sh', '2026-01-03')];
        $manager->syncFromFetcher();
        $this->assertSame(0, $manager->timerCountFor('id:1'), 'DISABLE → 0 Timer');
        $this->assertNotNull($manager->registry()->get('id:1'));

        $rows = [$this->row(1, '20', 1, 'a.sh', '2026-01-04')];
        $manager->syncFromFetcher();
        $this->assertSame(1, $manager->timerCountFor('id:1'), 'ENABLE → 1 Timer');
        $job = $manager->registry()->get('id:1');
        $this->assertGreaterThan(1000, $job?->nextRunAt, 'Enable ≠ Immediately Run');

        $rows = [];
        $manager->syncFromFetcher();
        $this->assertNull($manager->registry()->get('id:1'));
        $this->assertSame(0, $manager->timerCountFor('id:1'), 'DELETE → 0 Timer');
    }

    /**
     * 连续 UPDATE 不得堆积 Timer（禁止 clear-all + 全量重注册的副作用）。
     */
    public function testRepeatedUpdateNeverAccumulatesTimers(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $expr = '15';
        $rows = [$this->row(1, $expr, 1, 'a.sh')];
        $manager = $this->manager(function () use (&$rows) { return $rows; }, new RecordingExecutor(), $timer, $clock);
        $manager->start();

        foreach ([20, 30, 10, 25, 15] as $i => $nextExpr) {
            $expr = (string) $nextExpr;
            $rows = [$this->row(1, $expr, 1, 'a.sh', '2026-01-0' . ($i + 2))];
            $manager->syncFromFetcher();
            $this->assertSame(1, $manager->timerCountFor('id:1'), 'UPDATE ' . $expr . ' 后 Timer 必须仍为 1');
        }
        $this->assertSame(1, $timer->count());
    }

    /**
     * fetcher 抛异常 = DB 故障：保留 Last Known Good，Timer 仍在；恢复后按 Diff 收敛。
     */
    public function testDbFailureKeepsLastKnownGoodRuntime(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $fail = false;
        $rows = [$this->row(1, '15', 1, 'keep.sh')];
        $manager = $this->manager(function () use (&$fail, &$rows) {
            if ($fail) {
                throw new \RuntimeException('DB DOWN');
            }
            return $rows;
        }, new RecordingExecutor(), $timer, $clock);
        $manager->start();
        $this->assertSame(1, $manager->registry()->count());

        $fail = true;
        $ok = $manager->syncFromFetcher();
        $this->assertFalse($ok);
        $this->assertSame(1, $manager->registry()->count(), 'DB Down 期间 Runtime 不被清空');
        $this->assertSame(1, $manager->timerCountFor('id:1'));
        $this->assertNotNull($manager->lastConfigSyncError());

        $fail = false;
        $rows = [$this->row(1, '20', 1, 'new.sh', '2026-02-01'), $this->row(2, '15', 1, 'b.sh')];
        $manager->syncFromFetcher();
        $this->assertSame(2, $manager->registry()->count(), 'DB UP 后 Runtime 与配置一致');
        $this->assertSame('20', $manager->registry()->get('id:1')?->definition->expression);
    }

    /**
     * Worker Stop 必须显式清掉全部 Timer 与 Registry，不能只依赖析构。
     */
    public function testWorkerStopClearsAllTimersAndRuntime(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $manager = $this->manager(fn () => [
            $this->row(1, '15', 1, 'a.sh'),
            $this->row(2, '20', 1, 'b.sh'),
        ], new RecordingExecutor(), $timer, $clock);
        $manager->start();
        $this->assertGreaterThan(0, $timer->count());
        $manager->stop();
        $this->assertSame(0, $timer->count(), 'Worker Stop 后无残留 Timer');
        $this->assertSame(0, $manager->registry()->count());
    }

    /**
     * 旧 Manager stop 后新 Manager start，旧 Timer 必须为 0，新 Job 各 1 个 Timer。
     */
    public function testRestartDoesNotLeaveOldTimers(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $rows = array_map(fn (int $i) => $this->row($i, '15', 1, 'j' . $i . '.sh'), range(1, 20));
        $manager = $this->manager(function () use (&$rows) { return $rows; }, new RecordingExecutor(), $timer, $clock);
        $manager->start();
        $this->assertSame(20, $manager->registry()->count());
        $manager->stop();

        $timer2 = new ManualCronTimer();
        $manager2 = $this->manager(fn () => $rows, new RecordingExecutor(), $timer2, $clock);
        $manager2->start();
        $this->assertSame(0, $timer->count(), '旧 Timer 必须为 0');
        $this->assertSame(20, $manager2->registry()->count());
        foreach (range(1, 20) as $i) {
            $this->assertSame(1, $manager2->timerCountFor('id:' . $i));
        }
    }

    /**
     * with_block_lapping=1：执行中触发下一计划点必须 SKIP，不能排队或双开。
     */
    public function testBlockLappingSkipsOverlap(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $executor = new RecordingExecutor(function () use ($timer, $clock): void {
            $clock->advance(5);
            $timer->advance(5000);
        });
        $manager = $this->manager(
            fn () => [$this->row(1, '5', 1, 'sleep.sh', '2026-01-01', 1)],
            $executor,
            $timer,
            $clock,
        );
        $manager->start();
        $this->assertSame(1, $manager->timerCountFor('id:1'));

        $clock->set(5);
        $timer->advance(5000);
        $this->assertCount(1, $executor->snapshots, '重叠轮次必须 SKIP 而不是排队');
    }

    /**
     * with_block_lapping=0：允许重叠，第二轮 Execution 应启动。
     */
    public function testAllowOverlapRunsConcurrently(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $started = 0;
        $executor = new RecordingExecutor(function () use ($timer, $clock, &$started): void {
            ++$started;
            if ($started === 1) {
                $clock->advance(5);
                $timer->advance(5000);
            }
        });
        $manager = $this->manager(
            fn () => [$this->row(1, '5', 1, 'sleep.sh', '2026-01-01', 0)],
            $executor,
            $timer,
            $clock,
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);
        $this->assertGreaterThanOrEqual(2, count($executor->snapshots), '允许重叠时应启动第二轮 Execution');
    }

    /**
     * 执行中 UPDATE command：本轮 Snapshot 仍用旧 command，Runtime 已换成新定义。
     */
    public function testExecutionSnapshotIgnoresMidRunUpdate(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $rows = [$this->row(1, '5', 1, 'old.sh')];
        $seen = [];
        $manager = null;
        $executor = new RecordingExecutor(function (ExecutionSnapshot $snapshot) use (&$rows, &$manager, &$seen): void {
            $seen[] = $snapshot->definition->command;
            $rows = [$this->row(1, '5', 1, 'new.sh', '2026-02-01')];
            $manager?->syncFromFetcher();
        });
        $manager = $this->manager(function () use (&$rows) { return $rows; }, $executor, $timer, $clock);
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);
        $this->assertSame(['old.sh'], $seen, '当前 Execution 必须继续 old.sh');
        $this->assertSame('new.sh', $manager->registry()->get('id:1')?->definition->command);
    }

    /**
     * Job Exception ≠ Worker Exception：A 抛异常不得阻止 B 执行，双方 Timer 仍在。
     */
    public function testJobExceptionDoesNotStopOtherJobs(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $executor = new class implements \Swoolefy\Worker\Cron\CronExecutorInterface {
            public array $ran = [];
            public function run(ExecutionSnapshot $snapshot): ExecutionResult
            {
                $this->ran[] = $snapshot->definition->cronName;
                if ($snapshot->definition->cronName === 'boom') {
                    throw new \RuntimeException('job A crashed');
                }
                return ExecutionResult::success();
            }
        };
        $manager = $this->manager(fn () => [
            $this->row(1, '5', 1, 'a.sh', '2026-01-01', 0, 'boom'),
            $this->row(2, '5', 1, 'b.sh', '2026-01-01', 0, 'ok'),
        ], $executor, $timer, $clock);
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);
        $this->assertContains('boom', $executor->ran);
        $this->assertContains('ok', $executor->ran);
        $this->assertSame(1, $manager->timerCountFor('id:1'));
        $this->assertSame(1, $manager->timerCountFor('id:2'));
    }

    /**
     * nodeId 过滤发生在 Diff 之前：其它节点任务不得进入本 Worker Runtime。
     */
    public function testNodeIdDoesNotCrossNodes(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $manager = $this->manager(fn () => [
            $this->row(1, '15', 1, 'n1.sh') + ['node_id' => 1],
            $this->row(2, '15', 1, 'n2.sh') + ['node_id' => 2],
        ], new RecordingExecutor(), $timer, $clock, 1);
        $manager->start();
        $this->assertNotNull($manager->registry()->get('id:1'));
        $this->assertNull($manager->registry()->get('id:2'));
    }

    /**
     * cron_skip 命中则 SKIP 且计入 RuntimeMetrics，不调用 Executor。
     */
    public function testCronBetweenSkipAndMetrics(): void
    {
        RuntimeRegistry::initialize(['metrics' => ['enable' => true], 'diagnostics' => ['enable' => true]]);
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(strtotime('2026-08-15 10:00:00'));
        $executor = new RecordingExecutor();
        $messages = [];
        $manager = new CronManager(
            fetcher: fn () => [[
                'id' => 1,
                'name' => 'windowed',
                'expression' => '5',
                'command' => 'x.sh',
                'exec_type' => 1,
                'status' => 1,
                'cron_skip' => [['10:00:00', '10:00:30']],
            ]],
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            logWriter: function ($task, string $batchId, string $message) use (&$messages): void {
                unset($task, $batchId);
                $messages[] = $message;
            },
            metrics: new CronMetrics(RuntimeRegistry::metrics()),
        );
        $manager->start();
        $clock->set(strtotime('2026-08-15 10:00:05'));
        $timer->advance(5000);
        $this->assertSame([], $executor->commands, 'cron_skip 内应 SKIP');
        $this->assertTrue(
            array_reduce(
                $messages,
                static fn (bool $carry, string $message): bool => $carry || (str_contains($message, '跳过时段') && str_contains($message, '10:00:00-10:00:30')),
                false,
            ),
            '命中 cron_skip 时消息需包含跳过时段及窗口值',
        );
        $diag = $manager->diagnostics();
        $this->assertSame(1, $diag['job_count']);
        $this->assertSame(1, $diag['enabled_count']);
        $snapshot = RuntimeRegistry::metrics()?->snapshot();
        $this->assertGreaterThan(0, $snapshot['counter'][RuntimeMetrics::CRON_RUNS_SKIPPED] ?? 0);
    }

    /**
     * start 立刻心跳一次；之后按 conf heartbeat_interval tick；stop 清掉心跳 Timer。
     */
    public function testNodeHeartbeatTicksImmediatelyAndOnInterval(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $beats = [];
        $manager = new CronManager(
            fetcher: fn () => [$this->row(1, '15', 1, 'a.sh')],
            executor: new RecordingExecutor(),
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            nodeId: 9,
            heartbeatIntervalSeconds: 2,
            nodeHeartbeatAck: static function (string $nodeId, int $interval = 0) use (&$beats): void {
                $beats[] = ['nodeId' => $nodeId, 'interval' => $interval];
            },
        );
        $manager->start();
        $this->assertCount(1, $beats, 'start 必须立刻心跳，Admin 不等第一个 interval');
        $this->assertSame('9', $beats[0]['nodeId']);
        $this->assertSame(2, $beats[0]['interval'], 'ack 必须带上 conf 的 heartbeat_interval');
        $this->assertSame(2, $manager->diagnostics()['heartbeat_interval']);
        $this->assertSame(1000, $manager->diagnostics()['last_heartbeat_at']);

        $timer->advance(1999);
        $this->assertCount(1, $beats, '未到 interval 不得二次心跳');
        $timer->advance(1);
        $this->assertCount(2, $beats, '到达 heartbeat_interval 后 tick 心跳');

        $manager->stop();
        $this->assertSame(0, $timer->count(), 'stop 必须清掉心跳 tick');
        $timer->advance(4000);
        $this->assertCount(2, $beats, 'stop 后不得再心跳');
    }

    /**
     * 心跳 ack 抛异常必须隔离，不得阻止 Job 注册 / 后续心跳。
     */
    public function testNodeHeartbeatExceptionIsIsolated(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $beats = 0;
        $manager = new CronManager(
            fetcher: fn () => [$this->row(1, '15', 1, 'keep.sh')],
            executor: new RecordingExecutor(),
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            nodeId: 1,
            heartbeatIntervalSeconds: 3,
            nodeHeartbeatAck: static function (string $nodeId) use (&$beats): void {
                unset($nodeId);
                ++$beats;
                if ($beats === 1) {
                    throw new \RuntimeException('hb down');
                }
            },
        );
        $manager->start();
        $this->assertSame(1, $beats);
        $this->assertSame(1, $manager->registry()->count(), '心跳异常不得拖垮 Worker / Runtime');
        $this->assertNull($manager->diagnostics()['last_heartbeat_at'], 'ack 失败不记 last_heartbeat_at');
        $timer->advance(3000);
        $this->assertSame(2, $beats);
        $this->assertSame(1000, $manager->diagnostics()['last_heartbeat_at']);
    }

    /**
     * 无 ack 或无 nodeId 不武装心跳 Timer。
     */
    public function testHeartbeatSkippedWithoutAckOrNodeId(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $called = 0;
        $manager = $this->manager(fn () => [$this->row(1, '15', 1, 'a.sh')], new RecordingExecutor(), $timer, $clock);
        $manager->start();
        $jobTimers = $timer->count();
        $this->assertSame(1, $jobTimers);
        $manager->stop();

        $timer2 = new ManualCronTimer();
        $manager2 = new CronManager(
            fetcher: fn () => [],
            executor: new RecordingExecutor(),
            timer: $timer2,
            clock: $clock,
            pollIntervalMs: 0,
            nodeId: null,
            heartbeatIntervalSeconds: 15,
            nodeHeartbeatAck: static function () use (&$called): void {
                ++$called;
            },
        );
        $manager2->start();
        $this->assertSame(0, $called);
        $this->assertSame(0, $timer2->count());
    }

    /**
     * P0-2：DB 行存在但无法解析时保留 Last Known Good，不得 DELETE。
     * 任务明确从快照消失才允许 DELETE。
     */
    public function testInvalidRowKeepsLastKnownGoodRuntime(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(1000);
        $rows = [
            $this->row(1, '15', 1, 'a.sh'),
            $this->row(2, '15', 1, 'b.sh'),
            $this->row(3, '15', 1, 'c.sh'),
        ];
        $manager = $this->manager(function () use (&$rows) { return $rows; }, new RecordingExecutor(), $timer, $clock);
        $manager->start();
        $this->assertSame(3, $manager->registry()->count());
        $oldB = $manager->registry()->get('id:2')?->definition->command;
        $this->assertSame(1, $manager->timerCountFor('id:2'));

        $rows = [
            $this->row(1, '15', 1, 'a.sh'),
            [
                'id' => 2,
                'name' => 'job-2',
                'expression' => '',
                'command' => 'broken.sh',
                'exec_type' => 1,
                'status' => 1,
            ],
            $this->row(3, '15', 1, 'c.sh'),
        ];
        $manager->syncFromFetcher();

        $this->assertNotNull($manager->registry()->get('id:1'));
        $this->assertNotNull($manager->registry()->get('id:2'), '非法配置不得删除 Runtime B');
        $this->assertSame($oldB, $manager->registry()->get('id:2')?->definition->command);
        $this->assertSame(1, $manager->timerCountFor('id:2'), 'Last Known Good Timer 必须仍在');
        $this->assertNotNull($manager->registry()->get('id:3'));

        $rows = [
            $this->row(1, '15', 1, 'a.sh'),
            $this->row(3, '15', 1, 'c.sh'),
        ];
        $manager->syncFromFetcher();
        $this->assertNull($manager->registry()->get('id:2'), '任务明确不存在才允许 DELETE');
        $this->assertSame(0, $manager->timerCountFor('id:2'));
    }

    /**
     * P0-3：onTrigger 里 arm 失败必须隔离，不得拖垮 Worker；下一轮 Polling 补回 Timer。
     */
    public function testOnTriggerArmFailureIsIsolatedAndReconciled(): void
    {
        RuntimeRegistry::initialize(['metrics' => ['enable' => true], 'diagnostics' => ['enable' => true]]);
        $inner = new ManualCronTimer();
        $timer = new ThrowingAfterCronTimer($inner);
        $clock = new FrozenCronClock(0);
        $executor = new RecordingExecutor();
        $rows = [$this->row(1, '5', 1, 'keep.sh')];
        $manager = new CronManager(
            fetcher: function () use (&$rows) { return $rows; },
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            metrics: new CronMetrics(RuntimeRegistry::metrics()),
        );
        $manager->start();
        $this->assertSame(1, $manager->timerCountFor('id:1'));

        $timer->throwOnAfter = true;
        $clock->set(5);
        $timer->advance(5000);

        $this->assertSame([], $executor->commands, 'arm 失败本轮不得继续 Execution');
        $this->assertNotNull($manager->registry()->get('id:1'), 'Job 必须仍在 Runtime');
        $this->assertSame(0, $manager->timerCountFor('id:1'), 'arm 失败后 Timer=0');
        $snapshot = RuntimeRegistry::metrics()?->snapshot();
        $this->assertGreaterThan(0, $snapshot['counter'][RuntimeMetrics::CRON_SCHEDULER_ERRORS] ?? 0);

        $timer->throwOnAfter = false;
        $manager->syncFromFetcher();
        $this->assertSame(1, $manager->timerCountFor('id:1'), '下一轮 Polling 必须 reconcile 补回 Timer');
        $this->assertSame([], $executor->commands, 'reconcile 只补 Timer，不等于立刻执行');
    }

    /**
     * CronProcess 必须从 Worker args 读取 heartbeat_interval / node_heartbeat_ack。
     */
    public function testCronProcessWiresHeartbeatArgs(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(\Swoolefy\Worker\Cron\CronProcess::class))->getFileName()
        );
        $this->assertStringContainsString("'heartbeat_interval'", $src);
        $this->assertStringContainsString("'node_heartbeat_ack'", $src);
        $this->assertStringContainsString('heartbeatIntervalSeconds', $src);
        $this->assertStringContainsString('nodeHeartbeatAck', $src);
    }

    /**
     * Execution 日志写入结构化 status / trigger_type / duration_ms，不再只靠 message。
     * 同一 exec_batch_id 的开始(RUNNING)与结束(SUCCESS)都会带 status。
     */
    public function testExecutionLogWriterReceivesStructuredStatus(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $writes = [];
        $manager = $this->manager(
            fn () => [$this->row(1, '5', 1, 'ok.sh')],
            new RecordingExecutor(),
            $timer,
            $clock,
            null,
            function ($task, string $batchId, string $message, int $pid = 0, array $execution = []) use (&$writes): void {
                $writes[] = [
                    'batch' => $batchId,
                    'message' => $message,
                    'pid' => $pid,
                    'execution' => $execution,
                ];
            },
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $statuses = array_map(static fn (array $w) => $w['execution']['status'] ?? null, $writes);
        $this->assertContains(ExecutionStatus::RUNNING, $statuses);
        $this->assertContains(ExecutionStatus::SUCCESS, $statuses);
        $running = null;
        $final = null;
        foreach ($writes as $write) {
            $status = $write['execution']['status'] ?? null;
            if ($status === ExecutionStatus::RUNNING) {
                $running = $write;
            }
            if ($status === ExecutionStatus::SUCCESS) {
                $final = $write;
            }
        }
        $this->assertNotNull($running);
        $this->assertNotNull($final);
        $this->assertSame(ExecutionStatus::TRIGGER_SCHEDULER, $final['execution']['trigger_type']);
        $this->assertArrayHasKey('duration_ms', $final['execution']);
        $this->assertArrayHasKey('finished_at', $final['execution']);
        $this->assertSame($running['batch'], $final['batch']);
    }

    /**
     * 重叠 SKIP 必须写入 SKIPPED Execution，保证每一次计划调度都有结果行。
     */
    public function testSkipWritesSkippedStatus(): void
    {
        $timer = new ManualCronTimer();
        $clock = new FrozenCronClock(0);
        $writes = [];
        $executor = new RecordingExecutor(function () use ($timer, $clock): void {
            $clock->advance(5);
            $timer->advance(5000);
        });
        $manager = $this->manager(
            fn () => [$this->row(1, '5', 1, 'sleep.sh', '2026-01-01', 1)],
            $executor,
            $timer,
            $clock,
            null,
            function ($task, string $batchId, string $message, int $pid = 0, array $execution = []) use (&$writes): void {
                $writes[] = $execution;
            },
        );
        $manager->start();
        $clock->set(5);
        $timer->advance(5000);

        $skip = null;
        foreach ($writes as $execution) {
            if (($execution['status'] ?? null) === ExecutionStatus::SKIPPED) {
                $skip = $execution;
            }
        }
        $this->assertNotNull($skip, 'SKIPPED 必须落库');
        $this->assertSame(0, $skip['duration_ms']);
        $this->assertArrayHasKey('finished_at', $skip);
    }

    /**
     * 构造关闭 Polling 的 CronManager，便于手动推进 Timer。
     *
     * @param callable():list<array<string,mixed>> $fetcher
     * @param null|callable $logWriter
     */
    private function manager(
        callable $fetcher,
        \Swoolefy\Worker\Cron\CronExecutorInterface $executor,
        ManualCronTimer $timer,
        FrozenCronClock $clock,
        ?int $nodeId = null,
        $logWriter = null,
    ): CronManager {
        return new CronManager(
            fetcher: $fetcher,
            executor: $executor,
            timer: $timer,
            clock: $clock,
            pollIntervalMs: 0,
            nodeId: $nodeId,
            logWriter: $logWriter,
        );
    }

    /**
     * 最小合法 cron_task 行（id / name / expression / command / exec_type / status）。
     *
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        string $expression,
        int $status,
        string $command,
        string $updatedAt = '2026-01-01',
        int $block = 0,
        string $name = '',
    ): array {
        return [
            'id' => $id,
            'name' => $name !== '' ? $name : 'job-' . $id,
            'expression' => $expression,
            'command' => $command,
            'exec_type' => 1,
            'status' => $status,
            'with_block_lapping' => $block,
            'updated_at' => $updatedAt,
        ];
    }
}
