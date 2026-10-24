<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoole\Process;
use Swoolefy\Worker\MainManager;
use Swoolefy\Worker\Process\ProcessSupervisor;

/**
 * P0-02：子进程被信号终止时，除 Master 关机与动态缩容外必须重新拉起，不能从注册表静默移除。
 *
 * 使用真实 fork 子进程构造 wait 结果；rebootWorker 在 Manager stub 中只记录，不真正拉起。
 */
final class ProcessSupervisorSignalExitTest extends TestCase
{
    /** @var array<int, Process> */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $pid => $process) {
            if (Process::kill($pid, 0)) {
                Process::kill($pid, SIGKILL);
            }
        }
        while (Process::wait(false)) {
        }
        $this->children = [];
        parent::tearDown();
    }

    public function testUnexpectedSignalDeathRebootsWorker(): void
    {
        $manager = $this->newManagerStub();
        $process = $this->forkChild();
        $pid = (int) $process->pid;
        $manager->getRegistry()->replaceWorkers([
            md5('sig-demo') => [0 => $this->fakeProcess('sig-demo', 0, $pid, false, $process)],
        ]);

        Process::kill($pid, SIGKILL);

        $this->handleUntil(
            $manager->getSupervisor(),
            static fn (): bool => $manager->rebootedPids === [$pid],
        );

        $this->assertSame([$pid], $manager->rebootedPids);
        $this->assertTrue($manager->getRegistry()->hasWorker('sig-demo', 0));
        $this->assertSame([], $manager->exceptions);
    }

    public function testSignalDeathDuringMasterShutdownIsRemoved(): void
    {
        $manager = $this->newManagerStub();
        $process = $this->forkChild();
        $pid = (int) $process->pid;
        $manager->getRegistry()->replaceWorkers([
            md5('sig-demo') => [0 => $this->fakeProcess('sig-demo', 0, $pid, false, $process)],
        ]);
        $manager->getSignalShutdown()->setExiting(true);

        Process::kill($pid, SIGTERM);

        $this->handleUntil(
            $manager->getSupervisor(),
            static fn (): bool => !$manager->getRegistry()->hasWorker('sig-demo', 0),
        );

        $this->assertSame([], $manager->rebootedPids);
    }

    public function testSignalDeathOfStoppingDynamicProcessIsRemovedAndRecounted(): void
    {
        $manager = $this->newManagerStub();
        $process = $this->forkChild();
        $pid = (int) $process->pid;
        $processName = 'dyn-demo';
        $registry = $manager->getRegistry();
        $registry->replaceLists([
            md5($processName) => [
                'process_name' => $processName,
                'dynamic_process_worker_num' => 1,
                'dynamic_process_destroying' => true,
            ],
        ]);
        $registry->replaceWorkers([
            md5($processName) => [1 => $this->fakeProcess($processName, 1, $pid, true, $process)],
        ]);
        $registry->markStoppingDynamic($pid, $processName);

        Process::kill($pid, SIGTERM);

        $this->handleUntil(
            $manager->getSupervisor(),
            static fn (): bool => !$registry->hasWorker($processName, 1),
        );

        $this->assertSame([], $manager->rebootedPids);
        $this->assertSame(0, $registry->getList($processName)['dynamic_process_worker_num']);
        $this->assertFalse($registry->getList($processName)['dynamic_process_destroying']);
        $this->assertArrayNotHasKey($pid, $registry->stoppingDynamicProcesses());
    }

    public function testVoluntarySigtermExitIsRemovedWithoutReboot(): void
    {
        $manager = $this->newManagerStub();
        $process = $this->forkChild(SIGTERM);
        $pid = (int) $process->pid;
        $manager->getRegistry()->replaceWorkers([
            md5('sig-demo') => [0 => $this->fakeProcess('sig-demo', 0, $pid, false, $process)],
        ]);

        $this->handleUntil(
            $manager->getSupervisor(),
            static fn (): bool => !$manager->getRegistry()->hasWorker('sig-demo', 0),
        );

        $this->assertSame([], $manager->rebootedPids);
    }

    public function testVoluntarySigusr1ExitRebootsWorker(): void
    {
        $manager = $this->newManagerStub();
        $process = $this->forkChild(SIGUSR1);
        $pid = (int) $process->pid;
        $manager->getRegistry()->replaceWorkers([
            md5('sig-demo') => [0 => $this->fakeProcess('sig-demo', 0, $pid, false, $process)],
        ]);

        $this->handleUntil(
            $manager->getSupervisor(),
            static fn (): bool => $manager->rebootedPids === [$pid],
        );

        $this->assertSame([$pid], $manager->rebootedPids);
    }

    /**
     * 子进程可能在 kill 后尚未变成可 wait 状态，重试直到 rebootOrExitHandle 真正消费到它。
     */
    private function handleUntil(ProcessSupervisor $supervisor, callable $done): void
    {
        for ($i = 0; $i < 300; ++$i) {
            $supervisor->rebootOrExitHandle();
            if ($done()) {
                return;
            }
            usleep(10000);
        }

        $this->fail('exited child was not reaped by rebootOrExitHandle');
    }

    /**
     * $exitCode 为 null 时子进程常驻等待被信号终止；否则稍等后主动 exit($exitCode)。
     */
    private function forkChild(?int $exitCode = null): Process
    {
        $process = null;
        $process = new Process(static function () use (&$process, $exitCode): void {
            // 子进程不复制 PHPUnit 输出缓冲
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if ($exitCode === null) {
                while (true) {
                    usleep(100000);
                }
            }
            // 留出父进程注册 worker 的时间
            usleep(100000);
            $process->exit($exitCode);
        }, false);
        $process->start();
        $this->children[(int) $process->pid] = $process;

        return $process;
    }

    private function newManagerStub(): MainManager
    {
        return new class extends MainManager {
            /** @var list<int> */
            public array $rebootedPids = [];

            /** @var list<\Throwable> */
            public array $exceptions = [];

            public function __construct()
            {
            }

            public function rebootWorker(int $pid): void
            {
                $this->rebootedPids[] = $pid;
            }

            public function handleWorkerException(\Throwable $throwable): void
            {
                $this->exceptions[] = $throwable;
            }

            protected function fmtWriteInfo($msg)
            {
            }

            protected function fmtWriteError($msg)
            {
            }
        };
    }

    private function fakeProcess(
        string $name,
        int $workerId,
        int $pid,
        bool $dynamic,
        Process $swooleProcess,
    ): object {
        return new class ($name, $workerId, $pid, $dynamic, $swooleProcess) {
            public function __construct(
                private string $name,
                private int $workerId,
                private int $pid,
                private bool $dynamic,
                private Process $swooleProcess,
            ) {
            }

            public function isDynamicProcess(): bool
            {
                return $this->dynamic;
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

            public function getSwooleProcess(): Process
            {
                return $this->swooleProcess;
            }
        };
    }
}
