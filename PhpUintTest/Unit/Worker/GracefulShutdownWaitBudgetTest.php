<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Worker;

use PhpUintTest\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\MainManager;

/**
 * Graceful shutdown 等待预算统一。
 * 覆盖 Master 等待上限计算、非法 wait_time 启动失败、预算内不误杀与超时强杀日志。
 */
final class GracefulShutdownWaitBudgetTest extends TestCase
{
    /** @var list<string> */
    public array $infoLogs = [];

    /** @var list<string> */
    public array $errorLogs = [];

    /**
     * 测 resolveShutdownWaitSeconds：取各 Worker wait_time+maxWaitTimeOfExit 最大值并加清理余量。
     * 对应问题：硬编码 15s 短于 Worker 排空预算导致提前强杀。
     */
    public function testResolveShutdownWaitSecondsUsesWorkerDrainBudgetPlusMargin(): void
    {
        $manager = $this->newManagerStub();
        $this->setPrivate($manager, 'processWorkers', [
            md5('a') => [
                0 => $this->fakeWorker(10, 30, 1001, 'a', 0),
            ],
            md5('b') => [
                0 => $this->fakeWorker(20, 25, 1002, 'b', 0),
            ],
        ]);

        $method = new ReflectionMethod(MainManager::class, 'resolveShutdownWaitSeconds');
        $method->setAccessible(true);
        $seconds = $method->invoke($manager);

        // max(10+30, 20+25) + 5 = 50
        $this->assertSame(50.0, $seconds);
        $this->assertGreaterThanOrEqual(20 + 25, $seconds - MainManager::SHUTDOWN_CLEANUP_MARGIN_SECONDS);
    }

    /**
     * 测无 Worker 时回退默认排空预算 10+30+余量，不得短于该值。
     */
    public function testResolveShutdownWaitSecondsFallbackNotShorterThanDefaultDrain(): void
    {
        $manager = $this->newManagerStub();
        $this->setPrivate($manager, 'processWorkers', []);

        $method = new ReflectionMethod(MainManager::class, 'resolveShutdownWaitSeconds');
        $method->setAccessible(true);
        $seconds = $method->invoke($manager);

        $this->assertSame(10.0 + 30.0 + MainManager::SHUTDOWN_CLEANUP_MARGIN_SECONDS, $seconds);
        $this->assertGreaterThan(15.0, $seconds);
    }

    /**
     * 测 validateWorkerWaitTime：非法 wait_time 抛 WorkerException（启动失败）。
     */
    public function testInvalidWaitTimeRejectedAtAddProcess(): void
    {
        $manager = $this->newManagerStub();
        $this->expectException(WorkerException::class);
        $this->expectExceptionMessage('wait_time must be a positive number');
        $manager->addProcess('bad-wait', \stdClass::class, 1, true, ['wait_time' => 0]);
    }

    /**
     * 测非数字 wait_time 同样拒绝。
     */
    public function testNonNumericWaitTimeRejected(): void
    {
        $manager = $this->newManagerStub();
        $method = new ReflectionMethod(MainManager::class, 'validateWorkerWaitTime');
        $method->setAccessible(true);
        $this->expectException(WorkerException::class);
        $method->invoke($manager, ['wait_time' => 'abc'], 'p');
    }

    /**
     * 测预算内已退出的 PID 不会进入强杀分支，并记录等待开始/完成日志。
     */
    public function testWorkersExitedWithinBudgetAreNotForceKilled(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $pid = pcntl_fork();
        $this->assertNotFalse($pid);
        if ($pid === 0) {
            usleep(80000);
            exit(0);
        }

        $manager = $this->newManagerStub();
        $this->setPrivate($manager, 'processWorkers', [
            md5('quick') => [
                0 => $this->fakeWorker(1, 1, $pid, 'quick', 0),
            ],
        ]);

        $method = new ReflectionMethod(MainManager::class, 'waitWorkersExitOrKill');
        $method->setAccessible(true);
        $method->invoke($manager, 2.0);

        $status = 0;
        pcntl_waitpid($pid, $status, WNOHANG);
        $this->assertFalse(@posix_kill($pid, 0));
        $this->assertNotEmpty(array_filter(
            $this->infoLogs,
            static fn (string $msg): bool => str_contains($msg, 'wait workers exit begin')
        ));
        $this->assertNotEmpty(array_filter(
            $this->infoLogs,
            static fn (string $msg): bool => str_contains($msg, 'exited within shutdown budget')
        ));
        $this->assertEmpty(array_filter(
            $this->errorLogs,
            static fn (string $msg): bool => str_contains($msg, 'force kill')
        ));
    }

    /**
     * 测超过预算时按预期强杀并记录 PID。
     * 注：pcntl_fork 子进程可能先成僵尸，posix_kill(0) 仍为 true，故以日志 + waitpid 信号退出为准。
     */
    public function testForceKillWhenBudgetExceeded(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $pid = pcntl_fork();
        $this->assertNotFalse($pid);
        if ($pid === 0) {
            // 子进程故意卡住，触发 Master 超时强杀
            while (true) {
                usleep(200000);
            }
        }

        $manager = $this->newManagerStub();
        $this->setPrivate($manager, 'processWorkers', [
            md5('stuck') => [
                0 => $this->fakeWorker(1, 1, $pid, 'stuck', 0),
            ],
        ]);

        $method = new ReflectionMethod(MainManager::class, 'waitWorkersExitOrKill');
        $method->setAccessible(true);
        $method->invoke($manager, 0.25);

        $this->assertNotEmpty(array_filter(
            $this->errorLogs,
            static fn (string $msg): bool => str_contains($msg, 'enter force kill')
                && str_contains($msg, (string) $pid)
        ));
        $this->assertNotEmpty(array_filter(
            $this->errorLogs,
            static fn (string $msg): bool => str_contains($msg, 'force kill worker pid=' . $pid)
        ));

        $status = 0;
        $reaped = pcntl_waitpid($pid, $status);
        $this->assertSame($pid, $reaped);
        $this->assertTrue(pcntl_wifsignaled($status), 'child should exit by signal after force kill');
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
    }

    private function newManagerStub(): MainManager
    {
        $test = $this;

        return new class ($test) extends MainManager {
            public function __construct(private GracefulShutdownWaitBudgetTest $testCase)
            {
                // 跳过父构造，避免依赖 APP_PATH / 协程配置
            }

            protected function fmtWriteInfo($msg)
            {
                $this->testCase->infoLogs[] = (string) $msg;
            }

            protected function fmtWriteError($msg)
            {
                $this->testCase->errorLogs[] = (string) $msg;
            }
        };
    }

    private function fakeWorker(int $waitTime, int $maxExit, int $pid, string $name, int $workerId): object
    {
        return new class ($waitTime, $maxExit, $pid, $name, $workerId) {
            public function __construct(
                private int $waitTime,
                private int $maxExit,
                private int $pid,
                private string $name,
                private int $workerId,
            ) {
            }

            public function getWaitTime(): int
            {
                return $this->waitTime;
            }

            public function getMaxWaitTimeOfExit(): int
            {
                return $this->maxExit;
            }

            public function getPid(): int
            {
                return $this->pid;
            }

            public function getProcessName(): string
            {
                return $this->name;
            }

            public function getProcessWorkerId(): int
            {
                return $this->workerId;
            }
        };
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(MainManager::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}
