<?php

declare(strict_types=1);

namespace PHPUintTest\Coroutine\Http;

use PHPUintTest\CoroutineTestCase;
use Swoole\Coroutine;
use Swoolefy\Http\Health\Check\ProcessHealthCheck;
use Swoolefy\Http\Health\HealthCheckInterface;
use Swoolefy\Http\Health\HealthCheckResult;
use Swoolefy\Http\Health\HealthConfig;
use Swoolefy\Http\Health\HealthProbe;

/**
 * 阶段六 8.4：验证 HealthProbe 单项检查 TimeoutGuard，慢依赖在预算内返回。
 */
final class HealthCheckTimeoutTest extends CoroutineTestCase
{
    /**
     * 验证：慢检查在 check_timeout_seconds 预算内失败返回，不拖死整次探针。
     */
    public function testSlowCheckTimesOutWithinBudget(): void
    {
        $this->runInCoroutine(function (): void {
            $probe = new HealthProbe(HealthConfig::fromArray([
                'health' => [
                    'check_timeout_seconds' => 0.05,
                ],
            ]));

            $slow = new class implements HealthCheckInterface {
                public function name(): string
                {
                    return 'slow-dep';
                }

                public function check(): HealthCheckResult
                {
                    Coroutine::sleep(0.5);

                    return new HealthCheckResult('slow-dep', true, 'should-not-reach');
                }
            };

            $started = microtime(true);
            $report = $probe->run('readiness', [new ProcessHealthCheck(), $slow]);
            $elapsed = microtime(true) - $started;

            $this->assertFalse($report->ok);
            $this->assertSame('timeout', $report->checks[1]->meta['error_category'] ?? null);
            // 预算 0.05s；允许调度抖动，但仍远小于 sleep(0.5)
            $this->assertLessThan(0.35, $elapsed);
        });
    }

    /**
     * 验证：快速检查在超时预算内仍 ok，且响应不含敏感原文。
     */
    public function testFastCheckSucceedsAndSanitized(): void
    {
        $this->runInCoroutine(function (): void {
            $probe = new HealthProbe(HealthConfig::fromArray([
                'health' => [
                    'check_timeout_seconds' => 1,
                ],
            ]));

            $report = $probe->run('liveness', [new ProcessHealthCheck()]);
            $this->assertTrue($report->ok);
            $row = $report->checks[0]->toArray(true);
            $this->assertSame('up', $row['message']);
            $this->assertSame('ok', $row['meta']['error_category'] ?? null);
            $this->assertArrayHasKey('duration_ms', $row['meta'] ?? []);
        });
    }
}
