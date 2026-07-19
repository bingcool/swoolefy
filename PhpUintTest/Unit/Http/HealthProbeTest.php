<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use Swoolefy\Http\Health\Check\ProcessHealthCheck;
use Swoolefy\Http\Health\CheckFactory;
use Swoolefy\Http\Health\HealthCheckInterface;
use Swoolefy\Http\Health\HealthCheckResult;
use Swoolefy\Http\Health\HealthConfig;
use Swoolefy\Http\Health\HealthProbe;
use Swoolefy\Http\Health\HealthRoutes;

/**
 * K8s HTTP 探针（不依赖 Redis/DB）。
 */
final class HealthProbeTest extends TestCase
{
    protected function tearDown(): void
    {
        HealthRoutes::resetRegisteredFlag();
        parent::tearDown();
    }

    /**
     * 验证：空 liveness_checks 时仅进程检查且 ok。
     */
    public function testLivenessDefaultsToProcessOk(): void
    {
        $probe = new HealthProbe(HealthConfig::fromArray([
            'health' => [
                'liveness_checks' => [],
                'readiness_checks' => [],
            ],
        ]));

        $report = $probe->liveness();
        $this->assertTrue($report->ok);
        $this->assertSame('liveness', $report->probe);
        $this->assertCount(1, $report->checks);
        $this->assertSame('process', $report->checks[0]->name);
        $this->assertTrue($report->checks[0]->ok);
    }

    /**
     * 验证：readiness 任一检查失败则整体 unavailable。
     */
    public function testReadinessFailsWhenAnyCheckDown(): void
    {
        $probe = new HealthProbe(HealthConfig::fromArray([]));
        $down = new class implements HealthCheckInterface {
            public function name(): string
            {
                return 'fake-down';
            }

            public function check(): HealthCheckResult
            {
                return new HealthCheckResult('fake-down', false, 'boom');
            }
        };

        $report = $probe->run('readiness', [new ProcessHealthCheck(), $down]);
        $this->assertFalse($report->ok);
        $this->assertSame('unavailable', $report->toArray()['status']);
        $this->assertCount(2, $report->checks);
    }

    /**
     * 验证：路径与 aliases 合并去重。
     */
    public function testConfigMergesAliasPaths(): void
    {
        $config = HealthConfig::fromArray([
            'health' => [
                'liveness_path' => '/health',
                'readiness_path' => '/ready',
                'aliases' => [
                    'liveness' => ['/healthz', '/health'],
                    'readiness' => ['/readyz'],
                ],
            ],
        ]);

        $this->assertSame(['/health', '/healthz'], $config->livenessPaths());
        $this->assertSame(['/ready', '/readyz'], $config->readinessPaths());
    }

    /**
     * 验证：CheckFactory 识别内置 type。
     */
    public function testCheckFactoryBuildsProcessAndRedisDefs(): void
    {
        $checks = CheckFactory::fromDefs([
            ['type' => 'process'],
            ['type' => 'redis', 'component' => 'redis', 'name' => 'cache-redis'],
            ['type' => 'unknown'],
        ]);

        $this->assertCount(2, $checks);
        $this->assertSame('process', $checks[0]->name());
        $this->assertSame('cache-redis', $checks[1]->name());
    }
}
