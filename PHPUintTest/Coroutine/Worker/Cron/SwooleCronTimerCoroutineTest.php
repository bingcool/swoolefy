<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Worker\Cron;

use PHPUintTest\CoroutineTestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FrozenCronClock;
use PHPUintTest\Unit\Worker\Cron\Support\ManualCronTimer;
use PHPUintTest\Unit\Worker\Cron\Support\RecordingExecutor;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Core\Timer\Tick;
use Swoolefy\Worker\Cron\CronManager;
use Swoolefy\Worker\Cron\CronScheduler;
use Swoolefy\Worker\Cron\RuntimeJob;
use Swoolefy\Worker\Cron\ScheduleInterface;
use Swoolefy\Worker\Cron\SwooleCronTimer;
use Swoolefy\Worker\Cron\TaskDefinition;

/**
 * Swoole 6 协程不变量：生产 Timer 回调必须进协程。
 *
 * CronManager 的 one-shot Timer 若直接跑在事件循环，Swoole 6 会 Fatal。
 * SwooleCronTimer 走 Tick::afterTimer / tickTimer（内含 goApp + x-trace-id）；onTrigger 不再二次 go()。
 *
 * @see \Swoolefy\Worker\Cron\SwooleCronTimer
 * @see \Swoolefy\Worker\Cron\CronManager::onTrigger()
 */
final class SwooleCronTimerCoroutineTest extends CoroutineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseServer::setAppConf(['components' => []]);
    }

    protected function tearDown(): void
    {
        RuntimeRegistry::reset();
        parent::tearDown();
    }

    /**
     * after 回调的 cid 必须 >= 0（已进入协程），且带 Tick aftertimer_ x-trace-id。
     */
    public function testAfterCallbackRunsInsideCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            $ch = new Channel(1);
            $timer = new SwooleCronTimer();
            $timer->after(20, static function () use ($ch): void {
                $ch->push([
                    'cid' => Coroutine::getCid(),
                    'trace_id' => Context::get(Tick::CTX_X_TRACE_ID),
                ]);
            });
            $payload = $ch->pop(2);
            $timer->clearAll();
            $this->assertIsArray($payload);
            $this->assertGreaterThanOrEqual(0, $payload['cid'], 'SwooleCronTimer::after 必须经 Tick goApp 进协程');
            $this->assertIsString($payload['trace_id']);
            $this->assertStringStartsWith(Tick::TRACE_ID_PREFIX_AFTERTIMER, $payload['trace_id']);
        });
    }

    /**
     * tick（Config Polling）同样必须进协程，并带 Tick ticktimer_ x-trace-id。
     */
    public function testTickCallbackRunsInsideCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            $ch = new Channel(1);
            $timer = new SwooleCronTimer();
            $timer->tick(20, static function () use ($ch): void {
                $ch->push([
                    'cid' => Coroutine::getCid(),
                    'trace_id' => Context::get(Tick::CTX_X_TRACE_ID),
                ]);
            });
            $payload = $ch->pop(2);
            $timer->clearAll();
            $this->assertIsArray($payload);
            $this->assertGreaterThanOrEqual(0, $payload['cid'], 'SwooleCronTimer::tick 必须经 Tick goApp 进协程');
            $this->assertIsString($payload['trace_id']);
            $this->assertStringStartsWith(Tick::TRACE_ID_PREFIX_TICKTIMER, $payload['trace_id']);
        });
    }

    /**
     * CronScheduler 经生产 Timer 触发时，onTrigger 回调已在协程内。
     */
    public function testSchedulerTriggerHandlerRunsInsideCoroutine(): void
    {
        $this->runInCoroutine(function (): void {
            $ch = new Channel(1);
            $timer = new SwooleCronTimer();
            $clock = new FrozenCronClock(1000);
            $scheduler = new CronScheduler($timer, $clock);
            $scheduler->setTriggerHandler(static function (string $jobId) use ($ch): void {
                $ch->push(['jobId' => $jobId, 'cid' => Coroutine::getCid()]);
            });

            $definition = TaskDefinition::fromArray([
                'id' => 1,
                'name' => 'co-trigger',
                'expression' => '15',
                'command' => 'echo',
                'exec_type' => 1,
                'status' => 1,
            ]);
            $schedule = new class implements ScheduleInterface {
                public function calculateNextRunAt(int $fromTimestamp): int
                {
                    return $fromTimestamp;
                }

                public function expression(): string
                {
                    return 'immediate';
                }
            };
            $job = new RuntimeJob($definition->jobId, $definition, $schedule);
            $scheduler->arm($job);

            $payload = $ch->pop(2);
            $timer->clearAll();
            $this->assertIsArray($payload);
            $this->assertSame($definition->jobId, $payload['jobId']);
            $this->assertGreaterThanOrEqual(0, $payload['cid'], 'CronScheduler 触发回调必须在协程内');
        });
    }

    /**
     * onTrigger 已在协程内时：Executor 同协程执行，finally 释放 Guard，并已 arm 下一轮。
     */
    public function testOnTriggerExecutorSeesCoroutineAndReleasesGuard(): void
    {
        $this->runInCoroutine(function (): void {
            $seenCid = -1;
            $executor = new RecordingExecutor(static function () use (&$seenCid): void {
                $seenCid = Coroutine::getCid();
            });
            $timer = new ManualCronTimer();
            $clock = new FrozenCronClock(0);
            $manager = new CronManager(
                fetcher: fn () => [[
                    'id' => 1,
                    'name' => 'co-exec',
                    'expression' => '5',
                    'command' => 'echo',
                    'exec_type' => 1,
                    'status' => 1,
                    'with_block_lapping' => 1,
                ]],
                executor: $executor,
                timer: $timer,
                clock: $clock,
                pollIntervalMs: 0,
            );
            $manager->start();
            $manager->onTrigger('id:1');
            $this->assertGreaterThanOrEqual(0, $seenCid, 'onTrigger 已在协程内时 Executor 必须同协程执行');
            $this->assertCount(1, $executor->snapshots);
            $job = $manager->registry()->get('id:1');
            $this->assertNotNull($job);
            $this->assertFalse($job->running, 'finally 必须释放 Guard running');
            $this->assertSame(1, $manager->timerCountFor('id:1'), '先 arm 下一轮，Active 仍为 1 Timer');
            $manager->stop();
        });
    }
}
