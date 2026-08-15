<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ExecutionGuard;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionSnapshot;
use Swoolefy\Worker\Cron\ExpressionParser;
use Swoolefy\Worker\Cron\HttpExecutor;
use Swoolefy\Worker\Cron\RuntimeJob;
use Swoolefy\Worker\Cron\ShellExecutor;
use Swoolefy\Worker\Cron\TaskDefinition;
use Swoolefy\Worker\Cron\TimeWindowFilter;

/**
 * TimeWindowFilter / ExecutionGuard / ShellExecutor / HttpExecutor 单测。
 *
 * 覆盖：between/skip 窗、Guard 临界区、Shell 成功/非零/空命令、
 * HTTP 状态码与非法 URL、Timeout 隔离。不启动 CronManager。
 *
 * @see \Swoolefy\Worker\Cron\TimeWindowFilter
 * @see \Swoolefy\Worker\Cron\ExecutionGuard
 */
final class ExecutorAndWindowTest extends TestCase
{
    /**
     * allowed = inside(cron_between) && !inside(cron_skip)；时刻窗相对 $now 当天。
     */
    public function testTimeWindowBetweenAndSkip(): void
    {
        $filter = new TimeWindowFilter();
        $now = strtotime('2026-08-15 10:00:00');
        $allowed = TaskDefinition::fromArray([
            'id' => 1,
            'name' => 'w',
            'expression' => '15',
            'command' => 'x',
            'cron_between' => [['09:00:00', '11:00:00']],
        ]);
        $this->assertTrue($filter->evaluate($allowed, $now)['allowed']);

        $outside = TaskDefinition::fromArray([
            'id' => 1,
            'name' => 'w',
            'expression' => '15',
            'command' => 'x',
            'cron_between' => [['11:00:00', '12:00:00']],
        ]);
        $this->assertFalse($filter->evaluate($outside, $now)['allowed']);

        $skip = TaskDefinition::fromArray([
            'id' => 1,
            'name' => 'w',
            'expression' => '15',
            'command' => 'x',
            'cron_skip' => [['09:00:00', '11:00:00']],
        ]);
        $this->assertSame('cron_skip', $filter->evaluate($skip, $now)['reason']);
    }

    /**
     * with_block_lapping=1：tryBegin 在已 running 时返回 false（SKIP），end 后可再 begin。
     */
    public function testGuardCheckAndMarkIsAtomicForBlockLapping(): void
    {
        $job = $this->job(true);
        $guard = new ExecutionGuard();
        $this->assertTrue($guard->tryBegin($job));
        $this->assertFalse($guard->tryBegin($job), 'with_block_lapping=1 时最多一个 Running');
        $guard->end($job);
        $this->assertTrue($guard->tryBegin($job));
        $guard->end($job);
    }

    /**
     * with_block_lapping=0：允许重叠，runningCount 可 > 1，两次 end 后 running=false。
     */
    public function testGuardAllowsOverlapWhenDisabled(): void
    {
        $job = $this->job(false);
        $guard = new ExecutionGuard();
        $this->assertTrue($guard->tryBegin($job));
        $this->assertTrue($guard->tryBegin($job));
        $this->assertSame(2, $job->runningCount);
        $guard->end($job);
        $guard->end($job);
        $this->assertFalse($job->running);
    }

    /**
     * Shell 成功记录子进程 PID；非零退出为 FAILED 且带 exitCode。
     */
    public function testShellSuccessAndNonZeroExit(): void
    {
        $executor = new ShellExecutor();
        $ok = $executor->run($this->snapshot(PHP_BINARY . ' -r "echo 1;"'));
        $this->assertTrue($ok->isSuccess(), $ok->message);
        $this->assertGreaterThan(0, $ok->pid);

        $fail = $executor->run($this->snapshot(PHP_BINARY . ' -r "exit(2);"'));
        $this->assertSame(ExecutionResult::FAILED, $fail->status);
        $this->assertSame(2, $fail->exitCode);
    }

    /**
     * 空 command 返回 FAILED，不抛到测试进程（失败隔离）。
     */
    public function testShellExceptionIsolated(): void
    {
        $executor = new ShellExecutor();
        $result = $executor->run($this->snapshot(''));
        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertStringContainsString('为空', $result->message);
    }

    /**
     * 2xx SUCCESS；4xx/5xx 带 httpStatus 的 FAILED；非法 URL / 连接失败不抛异常。
     */
    public function testHttpStatusAndInvalidUrl(): void
    {
        $executor = new HttpExecutor(function (ExecutionSnapshot $snapshot): array {
            $url = $snapshot->definition->url;
            return match ($url) {
                'http://example.test/200' => ['status' => 200, 'body' => 'ok'],
                'http://example.test/400' => ['status' => 400, 'body' => 'bad'],
                'http://example.test/404' => ['status' => 404, 'body' => 'missing'],
                'http://example.test/500' => ['status' => 500, 'body' => 'err'],
                default => throw new \RuntimeException('connection refused'),
            };
        });

        $this->assertTrue($executor->run($this->httpSnapshot('http://example.test/200'))->isSuccess());
        $this->assertSame(400, $executor->run($this->httpSnapshot('http://example.test/400'))->httpStatus);
        $this->assertSame(404, $executor->run($this->httpSnapshot('http://example.test/404'))->httpStatus);
        $this->assertSame(500, $executor->run($this->httpSnapshot('http://example.test/500'))->httpStatus);
        $this->assertSame(ExecutionResult::FAILED, $executor->run($this->httpSnapshot('http://127.0.0.1:1/refused'))->status);
        $this->assertSame(ExecutionResult::FAILED, $executor->run($this->httpSnapshot('not-a-url'))->status);
    }

    /**
     * Guzzle Timeout 只让本轮 FAILED，消息含 timeout，不拖垮调用方。
     */
    public function testHttpTimeoutIsolated(): void
    {
        $executor = new HttpExecutor(static function (): array {
            throw new \GuzzleHttp\Exception\ConnectException(
                'cURL error 28: timeout',
                new \GuzzleHttp\Psr7\Request('GET', 'http://example.test/timeout'),
            );
        });
        $result = $executor->run($this->httpSnapshot('http://example.test/timeout'));
        $this->assertSame(ExecutionResult::FAILED, $result->status);
        $this->assertStringContainsString('timeout', strtolower($result->message));
    }

    /**
     * 构造带/不带 with_block_lapping 的 RuntimeJob，供 Guard 单测。
     */
    private function job(bool $block): RuntimeJob
    {
        $definition = TaskDefinition::fromArray([
            'id' => 1,
            'name' => 'g',
            'expression' => '15',
            'command' => 'x',
            'with_block_lapping' => $block ? 1 : 0,
        ]);

        return new RuntimeJob('id:1', $definition, (new ExpressionParser())->parse('15'));
    }

    /**
     * Shell 用冻结 Snapshot。
     */
    private function snapshot(string $command): ExecutionSnapshot
    {
        $definition = TaskDefinition::fromArray([
            'id' => 9,
            'name' => 'shell',
            'expression' => '15',
            'command' => $command,
            'exec_type' => 1,
        ]);

        return new ExecutionSnapshot('id:9', 'batch-1', $definition, time());
    }

    /**
     * HTTP 用冻结 Snapshot（exec_type=2）。
     */
    private function httpSnapshot(string $url): ExecutionSnapshot
    {
        $definition = TaskDefinition::fromArray([
            'id' => 8,
            'name' => 'http',
            'expression' => '15',
            'command' => $url,
            'url' => $url,
            'exec_type' => 2,
        ]);

        return new ExecutionSnapshot('id:8', 'batch-http', $definition, time());
    }
}
