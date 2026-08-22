<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\CronForkProcess;
use Swoolefy\Worker\Cron\CronForkRunner;
use Swoolefy\Worker\Cron\ExecutionResult;

final class CronForkProcessExecutionResultTest extends TestCase
{
    public function testProcOpenExitZeroMapsToSuccess(): void
    {
        $result = $this->mapResult([
            'pid' => 1234,
            'exit_code' => 0,
            'signaled' => false,
            'term_signal' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);

        $this->assertSame(ExecutionResult::SUCCESS, $result->status);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame(1234, $result->pid);
    }

    public function testProcOpenNonZeroMapsToFailed(): void
    {
        $result = $this->mapResult([
            'pid' => 99,
            'exit_code' => 7,
            'signaled' => false,
            'term_signal' => 0,
            'stdout' => '',
            'stderr' => 'boom',
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertSame(7, $result->exitCode);
        $this->assertStringContainsString('exitCode=7', $result->message);
    }

    public function testProcOpenSignalMapsToFailed(): void
    {
        $result = $this->mapResult([
            'pid' => 77,
            'exit_code' => 143,
            'signaled' => true,
            'term_signal' => 15,
            'stdout' => '',
            'stderr' => '',
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertSame(143, $result->exitCode);
        $this->assertStringContainsString('signal=15', $result->message);
    }

    public function testProcOpenTermSignalWithoutSignaledStillFailed(): void
    {
        $result = $this->mapResult([
            'pid' => 88,
            'exit_code' => 0,
            'signaled' => false,
            'term_signal' => 9,
            'stdout' => '',
            'stderr' => '',
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertStringContainsString('signal=9', $result->message);
    }

    public function testProcOpenPidNotPositiveAlwaysFailed(): void
    {
        $result = $this->mapResult([
            'pid' => 0,
            'exit_code' => 0,
            'signaled' => false,
            'term_signal' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertStringContainsString('拉起异常', $result->message);
    }

    public function testProcOpenMissingExitCodeMapsToFailed(): void
    {
        $result = $this->mapResult([
            'pid' => 123,
            'signaled' => false,
            'term_signal' => 0,
            'stdout' => '',
            'stderr' => 'fatal',
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertNull($result->exitCode);
        $this->assertStringContainsString('exitCode=', $result->message);
    }

    public function testProcOpenTailUsesStderrAndTruncatedHint(): void
    {
        $result = $this->mapResult([
            'pid' => 321,
            'exit_code' => 5,
            'signaled' => false,
            'term_signal' => 0,
            'stdout' => str_repeat('x', 400),
            'stderr' => "err line 1\nerr line 2",
            'stderr_truncated' => true,
        ]);

        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertStringContainsString('tailSource=stderr', $result->message);
        $this->assertStringContainsString('truncated=1', $result->message);
        $this->assertStringNotContainsString("\n", $result->message);
    }

    public function testRunnerSourceContainsDrainAndWaitPath(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronForkRunner::class))->getFileName()
        );

        $this->assertStringContainsString('waitForProcessExitAndDrainPipes', $src);
        $this->assertStringContainsString('appendPipeContent', $src);
        $this->assertStringContainsString('stream_set_blocking($pipes[1], false)', $src);
        $this->assertStringContainsString('stream_set_blocking($pipes[2], false)', $src);
        $this->assertStringContainsString('return $fn($command, $descriptors, $callable);', $src);
    }

    /**
     * @param array<string, mixed> $procResult
     */
    private function mapResult(array $procResult): ExecutionResult
    {
        $process = (new \ReflectionClass(CronForkProcess::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(CronForkProcess::class))->getMethod('buildProcOpenExecutionResult');
        $method->setAccessible(true);

        /** @var ExecutionResult $result */
        $result = $method->invoke($process, $procResult, 'demo');
        return $result;
    }
}

