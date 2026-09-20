<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Script\AbstractKernel;
use Swoolefy\Worker\Cron\CronForkRunner;
use Swoolefy\Worker\Dto\RunProcessMetaDtoWorker;

/**
 * P1-02：pid_file 未生成时不得用 10 次 GC 占槽；exec() 不得把原始 PID 写成 0。
 */
final class CronForkRunnerPidFileSlotTest extends TestCase
{
    /** @var list<int> */
    private array $childPids = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->childPids as $pid) {
            if ($pid > 0 && \Swoole\Process::kill($pid, 0)) {
                \Swoole\Process::kill($pid, SIGTERM);
            }
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testGcDropsDeadProcessWithoutPidFileImmediately(): void
    {
        $runner = $this->newRunner(1);
        $dto = $this->meta(999999999, $this->missingPidFile());
        $this->seedPool($runner, [$dto]);

        $left = $runner->gcExitProcess();

        $this->assertCount(0, $left, 'dead process without pid_file must release slot immediately');
    }

    public function testGcDropsZeroPidWithoutPidFileImmediately(): void
    {
        $runner = $this->newRunner(1);
        $this->seedPool($runner, [$this->meta(0, $this->missingPidFile())]);

        $this->assertCount(0, $runner->gcExitProcess());
        $this->assertTrue($runner->isNextHandle(true, 60));
    }

    public function testGcKeepsAliveProcessWaitingForSlowPidFile(): void
    {
        $runner = $this->newRunner(1);
        $dto = $this->meta(getmypid(), $this->missingPidFile());
        $this->seedPool($runner, [$dto]);

        $left = $runner->gcExitProcess();

        $this->assertCount(1, $left);
        $this->assertSame(1, $left[0]->check_pid_not_exist_count);
        $this->assertCount(1, $this->pool($runner), 'alive wrapper still occupies the slot');
    }

    public function testExecKeepsLaunchPidWhenPidFileMissingThenGcReleasesAfterExit(): void
    {
        $runner = $this->newRunner(1);
        $pidFile = $this->missingPidFile();
        $this->assertTrue($runner->isNextHandle(false, 60));

        $runner->exec('sleep', '8', [], true, '/dev/null', true, [
            AbstractKernel::getCronScriptPidFileOptionField() => $pidFile,
        ]);

        $pool = $this->pool($runner);
        $this->assertCount(1, $pool);
        $this->assertGreaterThan(0, $pool[0]->pid, 'exec must not wipe launch pid to 0');
        $this->assertSame($pidFile, $pool[0]->pid_file);
        $this->childPids[] = $pool[0]->pid;

        $this->assertCount(1, $runner->gcExitProcess(), 'alive process without pid_file still waits');
        $this->assertCount(1, $this->pool($runner), 'slot stays occupied until the process exits');

        \Swoole\Process::kill($pool[0]->pid, SIGTERM);
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && \Swoole\Process::kill($pool[0]->pid, 0)) {
            usleep(20000);
        }

        $this->assertCount(0, $runner->gcExitProcess(), 'dead process releases slot on next GC');
        $this->assertTrue($runner->isNextHandle(true, 60));
    }

    private function newRunner(int $concurrent): CronForkRunner
    {
        $runner = new CronForkRunner();
        $runner->setCronName('p1-cron-fork-' . uniqid('', true));
        $prop = new ReflectionProperty(CronForkRunner::class, 'concurrent');
        $prop->setAccessible(true);
        $prop->setValue($runner, $concurrent);

        return $runner;
    }

    /**
     * @param list<RunProcessMetaDtoWorker> $pool
     */
    private function seedPool(CronForkRunner $runner, array $pool): void
    {
        $prop = new ReflectionProperty(CronForkRunner::class, 'runProcessMetaPool');
        $prop->setAccessible(true);
        $prop->setValue($runner, $pool);
    }

    /**
     * @return list<RunProcessMetaDtoWorker>
     */
    private function pool(CronForkRunner $runner): array
    {
        $prop = new ReflectionProperty(CronForkRunner::class, 'runProcessMetaPool');
        $prop->setAccessible(true);
        $value = $prop->getValue($runner);

        return is_array($value) ? $value : [];
    }

    private function meta(int $pid, string $pidFile): RunProcessMetaDtoWorker
    {
        $dto = new RunProcessMetaDtoWorker();
        $dto->pid = $pid;
        $dto->pid_file = $pidFile;
        $dto->start_timestamp = time();
        $dto->command = 'sleep 8';

        return $dto;
    }

    private function missingPidFile(): string
    {
        $file = sys_get_temp_dir() . '/swoolefy-p1-cron-' . uniqid('', true) . '.pid';
        $this->tempFiles[] = $file;

        return $file;
    }
}
