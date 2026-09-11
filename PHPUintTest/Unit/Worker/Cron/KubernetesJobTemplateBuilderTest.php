<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Exception\CronException;
use Swoolefy\Worker\Cron\KubernetesJobSpec;
use Swoolefy\Worker\Cron\KubernetesJobTemplateBuilder;

/**
 * Deployment 模板 → 一次性 Job 的派生与消毒。
 *
 * 覆盖方案 §7 / §7.1 里「不做就会出事故」的每一条：探针杀容器、sidecar 不退出、
 * Service selector 把流量打到 Cron Pod、hostPort 抢端口、restartPolicy 非法。
 *
 * @see KubernetesJobTemplateBuilder
 */
final class KubernetesJobTemplateBuilderTest extends TestCase
{
    public function testStripsProbesLifecycleAndHostPort(): void
    {
        $job = $this->build();
        $container = $job['spec']['template']['spec']['containers'][0];

        $this->assertArrayNotHasKey('livenessProbe', $container);
        $this->assertArrayNotHasKey('readinessProbe', $container);
        $this->assertArrayNotHasKey('startupProbe', $container);
        $this->assertArrayNotHasKey('lifecycle', $container);
        $this->assertArrayNotHasKey('hostPort', $container['ports'][0]);
        $this->assertSame(8080, $container['ports'][0]['containerPort'], '普通端口声明保留');
    }

    public function testDropsServiceSelectorLabelsAndUsesOwnLabelSet(): void
    {
        $labels = $this->build()['spec']['template']['metadata']['labels'];

        $this->assertArrayNotHasKey('app', $labels, 'Service selector 命中就会把线上流量打到 Cron Pod');
        $this->assertArrayNotHasKey('version', $labels);
        $this->assertSame('schedule-job', $labels[KubernetesJobTemplateBuilder::LABEL_MANAGED_BY]);
        $this->assertSame('100', $labels[KubernetesJobTemplateBuilder::LABEL_CRON_ID]);
        $this->assertSame('abc123', $labels[KubernetesJobTemplateBuilder::LABEL_EXEC_BATCH_ID]);
        $this->assertSame('2', $labels[KubernetesJobTemplateBuilder::LABEL_ATTEMPT]);
    }

    public function testForcesRestartPolicyNeverAndBackoffLimitZero(): void
    {
        $job = $this->build();

        $this->assertSame('Never', $job['spec']['template']['spec']['restartPolicy']);
        $this->assertSame(0, $job['spec']['backoffLimit'], 'K8s 不负责业务重试');
        $this->assertSame(3600, $job['spec']['ttlSecondsAfterFinished']);
        $this->assertSame(700, $job['spec']['activeDeadlineSeconds']);
    }

    public function testOptsOutOfSidecarInjectionAndDropsInjectedAnnotations(): void
    {
        $annotations = $this->build()['spec']['template']['metadata']['annotations'];

        $this->assertSame('false', $annotations['sidecar.istio.io/inject'], 'sidecar 不退出会让 Job 永远 Complete 不了');
        $this->assertSame('disabled', $annotations['linkerd.io/inject']);
        $this->assertArrayNotHasKey('kubectl.kubernetes.io/last-applied-configuration', $annotations);
        $this->assertSame('v3', $annotations['checksum/config'], '业务注解保留，便于排障');
    }

    public function testKeepsOnlyTargetContainerAndReferencedVolumes(): void
    {
        $podSpec = $this->build()['spec']['template']['spec'];

        $this->assertCount(1, $podSpec['containers']);
        $this->assertSame('order-service', $podSpec['containers'][0]['name']);
        $this->assertSame(['app-config'], array_column($podSpec['volumes'], 'name'), 'sidecar 专用卷应随 sidecar 一起丢弃');
        $this->assertSame('order-sa', $podSpec['serviceAccountName'], '业务身份必须继承');
    }

    public function testOverridingCommandAlsoRemovesTemplateArgs(): void
    {
        $container = $this->build()['spec']['template']['spec']['containers'][0];

        $this->assertSame(['/app/bin/task'], $container['command']);
        $this->assertArrayNotHasKey(
            'args',
            $container,
            '只换 command 却留着模板 args，K8s 会拼成「新命令 + 旧参数」',
        );
    }

    public function testExplicitArgsReplaceTemplateArgs(): void
    {
        $container = $this->build([
            'command' => ['/app/bin/task'],
            'args' => ['reconcile', '--dry-run'],
        ])['spec']['template']['spec']['containers'][0];

        $this->assertSame(['reconcile', '--dry-run'], $container['args']);
    }

    public function testDropsNativeSidecarInitContainers(): void
    {
        $initContainers = $this->build()['spec']['template']['spec']['initContainers'];

        $this->assertSame(['migrate-check'], array_column($initContainers, 'name'));
    }

    public function testMultiContainerWithoutExplicitNameIsRejected(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessageMatches('/KUBERNETES_CONTAINER_NOT_FOUND/');

        $this->build(['container' => '']);
    }

    public function testUnknownContainerIsRejected(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessageMatches('/没有名为 nope 的容器/');

        $this->build(['container' => 'nope']);
    }

    public function testHostNetworkDnsPolicyFallsBackToClusterFirst(): void
    {
        $deployment = $this->deployment();
        $deployment['spec']['template']['spec']['hostNetwork'] = true;
        $deployment['spec']['template']['spec']['dnsPolicy'] = 'ClusterFirstWithHostNet';

        $podSpec = $this->build([], $deployment)['spec']['template']['spec'];

        $this->assertArrayNotHasKey('hostNetwork', $podSpec);
        $this->assertSame('ClusterFirst', $podSpec['dnsPolicy'], 'ClusterFirstWithHostNet 脱离 hostNetwork 就非法');
    }

    public function testPodLabelSelectorPinsBatchAndAttempt(): void
    {
        $selector = (new KubernetesJobTemplateBuilder())->podLabelSelector('abc123', 2);

        $this->assertStringContainsString('schedule-job.exec-batch-id=abc123', $selector);
        $this->assertStringContainsString('schedule-job.attempt=2', $selector);
    }

    /**
     * @param array<string, mixed> $specOverrides
     * @param array<string, mixed>|null $deployment
     * @return array<string, mixed>
     */
    private function build(array $specOverrides = [], ?array $deployment = null): array
    {
        $spec = KubernetesJobSpec::fromArray(array_merge([
            'namespace' => 'production',
            'deployment' => 'order-service',
            'container' => 'order-service',
            'command' => ['/app/bin/task'],
        ], $specOverrides));

        return (new KubernetesJobTemplateBuilder())->build($deployment ?? $this->deployment(), $spec, [
            'job_name' => 'sj-abc123-a2',
            'cron_id' => 100,
            'exec_batch_id' => 'abc123',
            'attempt' => 2,
            'ttl_seconds' => 3600,
            'active_deadline_seconds' => 700,
        ]);
    }

    /**
     * 一个「典型线上 Deployment」：带探针、sidecar、hostPort、selector labels 与注入注解。
     *
     * @return array<string, mixed>
     */
    private function deployment(): array
    {
        return [
            'spec' => [
                'selector' => ['matchLabels' => ['app' => 'order-service']],
                'template' => [
                    'metadata' => [
                        'labels' => ['app' => 'order-service', 'version' => 'v1'],
                        'annotations' => [
                            'checksum/config' => 'v3',
                            'sidecar.istio.io/inject' => 'true',
                            'kubectl.kubernetes.io/last-applied-configuration' => '{...}',
                        ],
                    ],
                    'spec' => [
                        'serviceAccountName' => 'order-sa',
                        'initContainers' => [
                            ['name' => 'migrate-check', 'image' => 'migrate:1'],
                            ['name' => 'log-agent', 'image' => 'log:1', 'restartPolicy' => 'Always'],
                        ],
                        'containers' => [
                            [
                                'name' => 'order-service',
                                'image' => 'registry.test/order-service:v1.8.7',
                                'args' => ['serve'],
                                'ports' => [['containerPort' => 8080, 'hostPort' => 8080]],
                                'livenessProbe' => ['httpGet' => ['path' => '/health', 'port' => 8080]],
                                'readinessProbe' => ['httpGet' => ['path' => '/ready', 'port' => 8080]],
                                'startupProbe' => ['httpGet' => ['path' => '/start', 'port' => 8080]],
                                'lifecycle' => ['preStop' => ['exec' => ['command' => ['sleep', '30']]]],
                                'volumeMounts' => [['name' => 'app-config', 'mountPath' => '/etc/app']],
                            ],
                            [
                                'name' => 'istio-proxy',
                                'image' => 'istio/proxyv2:1.20',
                                'volumeMounts' => [['name' => 'istio-certs', 'mountPath' => '/etc/certs']],
                            ],
                        ],
                        'volumes' => [
                            ['name' => 'app-config', 'configMap' => ['name' => 'order-config']],
                            ['name' => 'istio-certs', 'emptyDir' => []],
                        ],
                    ],
                ],
            ],
        ];
    }
}
