<?php

declare(strict_types=1);

namespace PhpUintTest\Coroutine\Core;

use PhpUintTest\CoroutineTestCase;
use ReflectionProperty;
use Swoolefy\Core\CommandRunner;
use Swoolefy\Worker\Dto\RunProcessMetaDtoWorker;

/**
 * 阶段一 P0-3.4（审计项 42）：CommandRunner 用真实 start_timestamp 判断超时。
 * 目标：并发上限未超时时拒绝拉起；真实超时才恢复；缺失/回拨字段不得误判或告警。
 */
final class CommandRunnerTimeoutTest extends CoroutineTestCase
{
    protected function tearDown(): void
    {
        $instances = new ReflectionProperty(CommandRunner::class, 'instances');
        $instances->setAccessible(true);
        $instances->setValue(null, []);
        parent::tearDown();
    }

    /**
     * 测达并发上限且任务未超时时 isNextHandle 返回 false。
     * 对应问题：误读不存在的 start_time 会使超时判断失效，并发上限形同虚设。
     */
    public function testRejectsWhenAtConcurrencyLimitAndNotTimedOut(): void
    {
        $this->runInCoroutine(function (): void {
            $runner = CommandRunner::getInstance('p0-cmd-runner-limit-' . uniqid('', true), 1);
            $this->seedPool($runner, [
                $this->meta(getmypid(), time()),
            ]);

            $this->assertFalse($runner->isNextHandle(true, 60));
        });
    }

    /**
     * 测最老任务超过配置超时后允许进入恢复分支（返回 true）。
     * 对应问题：真实超时进程应能被强制拉起下一个，避免槽位永久占满。
     */
    public function testAllowsRecoveryWhenOldestExceedsTimeout(): void
    {
        $this->runInCoroutine(function (): void {
            $runner = CommandRunner::getInstance('p0-cmd-runner-timeout-' . uniqid('', true), 1);
            $this->seedPool($runner, [
                $this->meta(getmypid(), time() - 120),
            ]);

            $this->assertTrue($runner->isNextHandle(true, 60));
        });
    }

    /**
     * 测 start_timestamp=0 视为未超时且不产生 start_time/未定义属性 warning。
     * 对应问题：旧数据缺字段或访问 start_time 会告警并误判超时。
     */
    public function testZeroStartTimestampDoesNotTreatAsTimedOutAndDoesNotWarnStartTime(): void
    {
        $this->runInCoroutine(function (): void {
            $warnings = [];
            set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });

            try {
                $runner = CommandRunner::getInstance('p0-cmd-runner-missing-' . uniqid('', true), 1);
                $this->seedPool($runner, [
                    $this->meta(getmypid(), 0),
                ]);

                $this->assertFalse($runner->isNextHandle(true, 60));
                foreach ($warnings as $warning) {
                    $this->assertStringNotContainsString('start_time', $warning);
                    $this->assertStringNotContainsString('Undefined property', $warning);
                }
            } finally {
                restore_error_handler();
            }
        });
    }

    /**
     * 测空进程池时允许拉起下一个进程。
     * 对应问题：空列表防御分支应放行，避免无在途任务时误阻塞调度。
     */
    public function testEmptyPoolAllowsNextHandle(): void
    {
        $this->runInCoroutine(function (): void {
            $runner = CommandRunner::getInstance('p0-cmd-runner-empty-' . uniqid('', true), 2);
            $this->seedPool($runner, []);
            $this->assertTrue($runner->isNextHandle(true, 60));
        });
    }

    /**
     * 测 start_timestamp 落在未来（时钟回拨/异常）时视为未超时。
     * 对应问题：时间回拨下不得因异常时间戳误走超时恢复、突破并发上限。
     */
    public function testFutureStartTimestampTreatedAsNotTimedOut(): void
    {
        $this->runInCoroutine(function (): void {
            $runner = CommandRunner::getInstance('p0-cmd-runner-clock-' . uniqid('', true), 1);
            $this->seedPool($runner, [
                $this->meta(getmypid(), time() + 3600),
            ]);

            $this->assertFalse($runner->isNextHandle(true, 60));
        });
    }

    /**
     * @param list<RunProcessMetaDtoWorker> $pool
     */
    private function seedPool(CommandRunner $runner, array $pool): void
    {
        $prop = new ReflectionProperty(CommandRunner::class, 'runProcessMetaPool');
        $prop->setAccessible(true);
        $prop->setValue($runner, $pool);
    }

    private function meta(int $pid, int $startTimestamp): RunProcessMetaDtoWorker
    {
        $dto = new RunProcessMetaDtoWorker();
        $dto->pid = $pid;
        $dto->start_timestamp = $startTimestamp;
        $dto->pid_file = '';

        return $dto;
    }
}
