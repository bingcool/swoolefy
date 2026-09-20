<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Worker\Cron;

use PHPUintTest\CoroutineTestCase;
use ReflectionProperty;
use Swoolefy\Script\AbstractKernel;
use Swoolefy\Worker\Cron\CronForkRunner;

/**
 * P1-02：procOpen 等待 pid_file 时，包装进程已死必须提前结束，活着则等到文件出现。
 */
final class CronForkRunnerPidFileWaitTest extends CoroutineTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testProcOpenUpdatesPidFromPidFile(): void
    {
        $this->runInCoroutine(function (): void {
            $pidFile = $this->tempFile('pid');
            $script = $this->writeScript(<<<'PHP'
<?php
file_put_contents($argv[1], (string) getmypid());
usleep(200000);
PHP);
            $runner = $this->newRunner();
            $this->assertTrue($runner->isNextHandle(false, 60));

            $status = [];
            $result = $runner->procOpen(PHP_BINARY, $script, [$pidFile], static function ($in, $out, $err, $statusProperty) use (&$status): void {
                $status = $statusProperty;
            }, $this->pidFileExtend($pidFile));

            $this->assertIsArray($result);
            $this->assertGreaterThan(0, (int) ($status['pid'] ?? 0));
            $this->assertSame((int) $status['pid'], (int) trim((string) file_get_contents($pidFile)));
        });
    }

    public function testProcOpenWaitsForDelayedPidFileWhileProcessAlive(): void
    {
        $this->runInCoroutine(function (): void {
            $pidFile = $this->tempFile('pid');
            $script = $this->writeScript(<<<'PHP'
<?php
usleep(350000);
file_put_contents($argv[1], (string) getmypid());
usleep(100000);
PHP);
            $runner = $this->newRunner();
            $this->assertTrue($runner->isNextHandle(false, 60));

            $started = microtime(true);
            $status = [];
            $runner->procOpen(PHP_BINARY, $script, [$pidFile], static function ($in, $out, $err, $statusProperty) use (&$status): void {
                $status = $statusProperty;
            }, $this->pidFileExtend($pidFile));

            $this->assertGreaterThan(0.25, microtime(true) - $started, 'must wait until delayed pid_file appears');
            $this->assertSame((int) trim((string) file_get_contents($pidFile)), (int) ($status['pid'] ?? 0));
        });
    }

    public function testProcOpenDoesNotWaitFullTimeoutWhenProcessDiesWithoutPidFile(): void
    {
        $this->runInCoroutine(function (): void {
            $pidFile = $this->tempFile('pid');
            $runner = $this->newRunner(1);
            $this->assertTrue($runner->isNextHandle(false, 60));

            $started = microtime(true);
            $runner->procOpen(PHP_BINARY, '-r "exit(0);"', [], static function (): void {
            }, $this->pidFileExtend($pidFile));
            $elapsed = microtime(true) - $started;

            $this->assertLessThan(2.0, $elapsed, 'dead wrapper must not spin until CRON_MAX_WAIT_FORK_TIME');
            $this->assertFileDoesNotExist($pidFile);
            $this->assertCount(0, $this->pool($runner), 'failed start must not occupy a slot');
            $this->assertTrue($runner->isNextHandle(true, 60));
        });
    }

    private function newRunner(int $concurrent = 5): CronForkRunner
    {
        $runner = new CronForkRunner();
        $runner->setCronName('p1-cron-procopen-' . uniqid('', true));
        $prop = new ReflectionProperty(CronForkRunner::class, 'concurrent');
        $prop->setAccessible(true);
        $prop->setValue($runner, $concurrent);

        return $runner;
    }

    /**
     * @return list<mixed>
     */
    private function pool(CronForkRunner $runner): array
    {
        $prop = new ReflectionProperty(CronForkRunner::class, 'runProcessMetaPool');
        $prop->setAccessible(true);
        $value = $prop->getValue($runner);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, string>
     */
    private function pidFileExtend(string $pidFile): array
    {
        return [
            AbstractKernel::getCronScriptPidFileOptionField() => $pidFile,
        ];
    }

    private function writeScript(string $code): string
    {
        $file = $this->tempFile('php');
        file_put_contents($file, $code);

        return $file;
    }

    private function tempFile(string $suffix): string
    {
        $file = sys_get_temp_dir() . '/swoolefy-p1-cron-' . uniqid('', true) . '.' . $suffix;
        $this->tempFiles[] = $file;

        return $file;
    }
}
