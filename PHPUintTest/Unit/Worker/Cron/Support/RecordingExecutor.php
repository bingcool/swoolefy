<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron\Support;

use Swoolefy\Worker\Cron\CronExecutorInterface;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionSnapshot;

/**
 * 记录每次 Execution Snapshot，并可在执行中回调。
 *
 * 用于重叠 SKIP、Snapshot 冻结、异常隔离等 CronManager 单测。
 * $onRun 在记录之后、返回结果之前调用，可在其中 advance Timer 或 syncFromFetcher。
 * $throw=true 模拟 Job Exception；$fail=true 返回 FAILED 而不抛。
 *
 * @see \Swoolefy\Worker\Cron\CronExecutorInterface
 */
final class RecordingExecutor implements CronExecutorInterface
{
    /** @var list<ExecutionSnapshot> */
    public array $snapshots = [];

    /** @var list<string> */
    public array $commands = [];

    public function __construct(
        private readonly mixed $onRun = null,
        private readonly bool $throw = false,
        private readonly bool $fail = false,
    ) {
    }

    /**
     * 记录 snapshot / command，再按构造参数 throw / fail / success。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        $this->snapshots[] = $snapshot;
        $this->commands[] = $snapshot->definition->command;
        if (is_callable($this->onRun)) {
            ($this->onRun)($snapshot, $this);
        }
        if ($this->throw) {
            throw new \RuntimeException('executor boom');
        }
        if ($this->fail) {
            return ExecutionResult::failed('executor failed');
        }

        return ExecutionResult::success('ok');
    }
}
