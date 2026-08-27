<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Core\Schedule\ScheduleEvent;
use Swoolefy\Worker\Cron\CronForkProcess;
use Swoolefy\Worker\Cron\CronForkRunner;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionStatus;

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

    public function testReceiveCallBackLogsProcOpenPidWithRunningStatus(): void
    {
        $captured = [];
        $process = new class($captured) extends CronForkProcess {
            /** @var list<array<string, mixed>> */
            private array $captured;

            /** @param list<array<string, mixed>> $captured */
            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            protected function logCronTaskRuntime(
                $scheduleTask,
                string $execBatchId,
                string $message,
                int $pid = 0,
                array $execution = [],
            ): void {
                $this->captured[] = [
                    'execBatchId' => $execBatchId,
                    'message' => $message,
                    'pid' => $pid,
                    'execution' => $execution,
                ];
            }
        };

        $scheduleTask = new ScheduleEvent();
        $scheduleTask->cron_task_id = 1;

        $method = (new \ReflectionClass(CronForkProcess::class))->getMethod('receiveCallBack');
        $method->setAccessible(true);
        $method->invoke(
            $process,
            null,
            null,
            null,
            ['pid' => 4242, 'exec_batch_id' => 'batch-1'],
            $scheduleTask,
        );

        $this->assertCount(1, $captured);
        $this->assertSame('batch-1', $captured[0]['execBatchId']);
        $this->assertSame(4242, $captured[0]['pid']);
        $this->assertSame(ExecutionStatus::RUNNING, $captured[0]['execution']['status']);
        $this->assertStringContainsString('PROC_OPEN 拉起脚本的进程PID=4242', $captured[0]['message']);
    }

    public function testProcOpenCallbackRunsBeforeWaitForExit(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronForkRunner::class))->getFileName()
        );

        $this->assertLessThan(
            strpos($src, 'waitForProcessExitAndDrainPipes'),
            strpos($src, 'call_user_func_array($callable, $params);'),
            'proc_open 回调必须在 waitForProcessExitAndDrainPipes 之前，以便 PID 日志及时落库',
        );
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

