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
use Swoolefy\Http\Health\InvalidHealthCheckConfigException;

/**
 * K8s HTTP 探针单元测试（阶段六 8.4：未知类型 fail closed、响应脱敏）。
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
                return new HealthCheckResult('fake-down', false, 'boom password=secret dsn=mysql://u:p@h/db');
            }
        };

        $report = $probe->run('readiness', [new ProcessHealthCheck(), $down]);
        $this->assertFalse($report->ok);
        $this->assertSame('unavailable', $report->toArray()['status']);
        $this->assertCount(2, $report->checks);

        // 响应不得暴露 DSN / 凭证；仅名称、状态、耗时、错误分类
        $downRow = $report->checks[1]->toArray(true);
        $this->assertSame('fake-down', $downRow['name']);
        $this->assertSame('down', $downRow['status']);
        $this->assertStringNotContainsString('password', $downRow['message']);
        $this->assertStringNotContainsString('mysql://', $downRow['message']);
        $this->assertArrayHasKey('error_category', $downRow['meta'] ?? []);
        $this->assertArrayHasKey('duration_ms', $downRow['meta'] ?? []);
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
     * 验证：CheckFactory 识别内置 type；未知 type 抛配置异常（启动 fail closed）。
     */
    public function testCheckFactoryBuildsProcessAndRedisDefs(): void
    {
        $checks = CheckFactory::fromDefs([
            ['type' => 'process'],
            ['type' => 'redis', 'component' => 'redis', 'name' => 'cache-redis'],
        ]);

        $this->assertCount(2, $checks);
        $this->assertSame('process', $checks[0]->name());
        $this->assertSame('cache-redis', $checks[1]->name());
    }

    /**
     * 验证：未知 health check type 抛 InvalidHealthCheckConfigException。
     */
    public function testUnknownCheckTypeThrowsAtFactory(): void
    {
        $this->expectException(InvalidHealthCheckConfigException::class);
        $this->expectExceptionMessage('unknown health check type');
        CheckFactory::fromDefs([
            ['type' => 'process'],
            ['type' => 'unknown'],
        ]);
    }

    /**
     * 验证：HealthRoutes::register 在未知 type 时启动失败（不注册路由）。
     */
    public function testRegisterFailsOnUnknownCheckType(): void
    {
        $this->expectException(InvalidHealthCheckConfigException::class);
        HealthRoutes::register(HealthConfig::fromArray([
            'health' => [
                'enabled' => true,
                'readiness_checks' => [
                    ['type' => 'not_a_real_check'],
                ],
            ],
        ]));
    }

    /**
     * 验证：file_storage type 可构造，并解析 disk / probe_path。
     */
    public function testCheckFactoryBuildsFileStorageDef(): void
    {
        $checks = CheckFactory::fromDefs([
            [
                'type' => 'file_storage',
                'component' => 'file_storage',
                'disk' => 'aliyun_oss',
                'probe_path' => '.health/probe',
                'name' => 'storage-oss',
            ],
            ['type' => 'storage', 'disk' => 'local'],
        ]);

        $this->assertCount(2, $checks);
        $this->assertSame('storage-oss', $checks[0]->name());
        $this->assertSame('file_storage', $checks[1]->name());
    }

    /**
     * 验证：check_timeout_seconds 配置可读，非法值回落默认 2。
     */
    public function testCheckTimeoutSecondsDefault(): void
    {
        $config = HealthConfig::fromArray([
            'health' => [
                'check_timeout_seconds' => 3,
            ],
        ]);
        $this->assertSame(3.0, $config->checkTimeoutSeconds());

        $fallback = HealthConfig::fromArray([
            'health' => [
                'check_timeout_seconds' => 0,
            ],
        ]);
        $this->assertSame(2.0, $fallback->checkTimeoutSeconds());
    }
}
