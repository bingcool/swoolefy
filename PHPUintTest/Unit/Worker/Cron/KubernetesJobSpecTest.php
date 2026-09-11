<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Exception\CronException;
use Swoolefy\Worker\Cron\KubernetesJobSpec;

/**
 * `cron_task.k8s_spec` 的解析与校验（方案 §5）。
 *
 * @see KubernetesJobSpec
 */
final class KubernetesJobSpecTest extends TestCase
{
    public function testParsesArrayForm(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'container' => 'order-service',
            'command' => ['/app/bin/task'],
            'args' => ['reconcile'],
        ]);

        $this->assertSame('production', $spec->namespace);
        $this->assertSame(['/app/bin/task'], $spec->command);
        $this->assertSame(['reconcile'], $spec->args);
        $this->assertTrue($spec->overridesCommand);
        $this->assertTrue($spec->overridesArgs);
    }

    public function testMissingCommandAndArgsMeansInheritImageEntrypoint(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
        ]);

        $this->assertFalse($spec->overridesCommand);
        $this->assertFalse($spec->overridesArgs);
        $this->assertSame('', $spec->container, '单容器 Deployment 允许留空由 Builder 自动选中');
    }

    public function testExplicitEmptyArgsClearsImageCmd(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'args' => [],
        ]);

        $this->assertTrue($spec->overridesArgs, '显式空数组与「没填」是两种语义');
        $this->assertSame([], $spec->args);
    }

    public function testAcceptsMultilineTextAsArgv(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'command' => "/app/bin/task\n  reconcile  \n\n--dry-run",
        ]);

        $this->assertSame(['/app/bin/task', 'reconcile', '--dry-run'], $spec->command);
    }

    public function testAcceptsJsonStringForm(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'args' => '["reconcile","--dry-run"]',
        ]);

        $this->assertSame(['reconcile', '--dry-run'], $spec->args);
    }

    public function testRejectsMissingNamespace(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('k8s_spec.namespace 为必填');

        KubernetesJobSpec::fromArray(['deployment' => 'order-service']);
    }

    public function testRejectsNonDnsName(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessageMatches('/不是合法的 Kubernetes 名称/');

        KubernetesJobSpec::fromArray([
            'namespace' => 'Production_1',
            'deployment' => 'order-service',
        ]);
    }

    public function testRejectsNestedArgv(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessageMatches('/每一项必须是字符串/');

        KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'command' => [['nested']],
        ]);
    }

    public function testSummaryIsHumanReadable(): void
    {
        $spec = KubernetesJobSpec::fromArray([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'container' => 'order-service',
            'command' => ['/app/bin/task'],
            'args' => ['reconcile'],
        ]);

        $this->assertSame('production/order-service[order-service] /app/bin/task reconcile', $spec->summary());
    }
}
