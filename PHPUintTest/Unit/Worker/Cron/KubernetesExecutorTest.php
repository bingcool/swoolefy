<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use PHPUintTest\Unit\Worker\Cron\Support\FakeKubernetesClient;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\ExecutionSnapshot;
use Swoolefy\Worker\Cron\KubernetesExecutionHookInterface;
use Swoolefy\Worker\Cron\KubernetesExecutor;
use Swoolefy\Worker\Cron\KubernetesExecutorOptions;
use Swoolefy\Worker\Cron\KubernetesJobTemplateBuilder;
use Swoolefy\Worker\Cron\TaskDefinition;

/**
 * Kubernetes 执行器的编排行为。
 *
 * 重点覆盖方案里点名的几处陷阱：
 * - Job 名必须带 attempt，否则重试会 409 到上一次失败的 Job（§9.2）
 * - Create 前必须先落 Job 名，否则崩溃即丢句柄（§12）
 * - 取消 / 超时必须真的 DELETE Job，否则留下孤儿（§11.1）
 * - Namespace 白名单与「必须配 timeout」是硬闸门（§5.2、§11.2）
 *
 * @see KubernetesExecutor
 */
final class KubernetesExecutorTest extends TestCase
{
    public function testJobNameCarriesBatchAndAttempt(): void
    {
        $this->assertSame('sj-3f9a1c8e2b7d0456-a1', KubernetesExecutor::jobName('3f9a1c8e2b7d0456', 1));
        $this->assertSame('sj-3f9a1c8e2b7d0456-a3', KubernetesExecutor::jobName('3f9a1c8e2b7d0456', 3));
        $this->assertLessThanOrEqual(63, strlen(KubernetesExecutor::jobName('3f9a1c8e2b7d0456', 3)));
    }

    public function testSuccessfulJobReportsSuccessWithPodLogTail(): void
    {
        $client = $this->client();
        $client->jobStates = [['status' => ['succeeded' => 1]]];
        $client->pods = [[
            'metadata' => ['name' => 'sj-abc123-a1-x9f2'],
            'status' => ['containerStatuses' => [
                ['name' => 'order-service', 'state' => ['terminated' => ['exitCode' => 0]]],
            ]],
        ]];
        $client->podLog = 'reconciled 42 orders';

        $result = $this->execute($client);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('reconciled 42 orders', $result->message);
    }

    public function testFailedJobCarriesExitCodeAndReason(): void
    {
        $client = $this->client();
        $client->jobStates = [[
            'status' => ['conditions' => [
                ['type' => 'Failed', 'status' => 'True', 'reason' => 'BackoffLimitExceeded', 'message' => 'boom'],
            ]],
        ]];
        $client->pods = [[
            'metadata' => ['name' => 'sj-abc123-a1-x9f2'],
            'status' => ['containerStatuses' => [
                ['name' => 'order-service', 'state' => ['terminated' => ['exitCode' => 137]]],
            ]],
        ]];

        $result = $this->execute($client);

        $this->assertTrue($result->isFailed());
        $this->assertSame(137, $result->exitCode);
        $this->assertStringContainsString('BackoffLimitExceeded', $result->message);
    }

    public function testDeadlineExceededIsTimeoutNotFailure(): void
    {
        $client = $this->client();
        $client->jobStates = [[
            'status' => ['conditions' => [
                ['type' => 'Failed', 'status' => 'True', 'reason' => 'DeadlineExceeded'],
            ]],
        ]];

        $this->assertTrue($this->execute($client)->isTimeout());
    }

    public function testJobNameIsPersistedBeforeCreate(): void
    {
        $client = $this->client();
        $client->jobStates = [['status' => ['succeeded' => 1]]];
        $hook = new RecordingKubernetesHook();

        $this->execute($client, hook: $hook);

        $this->assertSame(
            ['planned:sj-abc123-a1', 'created:sj-abc123-a1', 'finished'],
            $hook->events,
            'Create 成功后立刻崩溃时，只有事先落库的 Job 名能救回句柄',
        );
    }

    public function testCancelRequestDeletesJobInsteadOfLeavingOrphan(): void
    {
        $client = $this->client();
        $client->jobStates = [['status' => []]];
        $hook = new RecordingKubernetesHook();
        // 前两次放行（执行前检查 + 首轮轮询），第三次报取消
        $hook->signals = [
            KubernetesExecutionHookInterface::STOP_NONE,
            KubernetesExecutionHookInterface::STOP_CANCELLED,
        ];

        $result = $this->execute($client, hook: $hook);

        $this->assertSame(ExecutionResult::CANCELLED, $result->status);
        $this->assertSame(['sj-abc123-a1'], $client->deleted);
    }

    public function testCancelBeforeCreateSkipsClusterEntirely(): void
    {
        $client = $this->client();
        $hook = new RecordingKubernetesHook();
        $hook->signals = [KubernetesExecutionHookInterface::STOP_CANCELLED];

        $result = $this->execute($client, hook: $hook);

        $this->assertSame(ExecutionResult::CANCELLED, $result->status);
        $this->assertSame([], $client->createCalls);
    }

    /**
     * runWithRetry 对 TIMEOUT 也会重试，若不在建 Job 前拦住，Guard 已经收尾的
     * Execution 还会再起一个没人收割的 Job。
     */
    public function testAlreadyTimedOutExecutionDoesNotCreateAnotherJob(): void
    {
        $client = $this->client();
        $hook = new RecordingKubernetesHook();
        $hook->signals = [KubernetesExecutionHookInterface::STOP_TIMEOUT];

        $result = $this->execute($client, hook: $hook, attempt: 2);

        $this->assertTrue($result->isTimeout());
        $this->assertSame([], $client->createCalls);
    }

    public function testConflictOnSameBatchIsIdempotent(): void
    {
        $client = $this->client();
        $client->createConflicts = true;
        $client->jobStates = [[
            'metadata' => ['labels' => [
                KubernetesJobTemplateBuilder::LABEL_EXEC_BATCH_ID => 'abc123',
                KubernetesJobTemplateBuilder::LABEL_ATTEMPT => '1',
            ]],
            'status' => ['succeeded' => 1],
        ]];

        $this->assertTrue($this->execute($client)->isSuccess());
    }

    public function testConflictWithForeignJobFails(): void
    {
        $client = $this->client();
        $client->createConflicts = true;
        $client->jobStates = [[
            'metadata' => ['labels' => [
                KubernetesJobTemplateBuilder::LABEL_EXEC_BATCH_ID => 'someone-else',
                KubernetesJobTemplateBuilder::LABEL_ATTEMPT => '1',
            ]],
            'status' => ['succeeded' => 1],
        ]];

        $result = $this->execute($client);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('KUBERNETES_JOB_NAME_CONFLICT', $result->message);
    }

    public function testNamespaceOutsideAllowListIsRejectedBeforeTouchingCluster(): void
    {
        $client = $this->client();
        $options = new KubernetesExecutorOptions(allowedNamespaces: ['staging'], pollIntervalSeconds: 1);

        $result = $this->execute($client, options: $options);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('KUBERNETES_NAMESPACE_DENIED', $result->message);
        $this->assertSame([], $client->createCalls);
    }

    public function testMissingTimeoutIsRejected(): void
    {
        $result = $this->execute($this->client(), timeout: 0);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('KUBERNETES_TIMEOUT_REQUIRED', $result->message);
    }

    public function testInvalidSpecFailsWithoutCallingApi(): void
    {
        $client = $this->client();
        $result = $this->execute($client, k8sSpec: ['namespace' => 'production']);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('KUBERNETES_SPEC_INVALID', $result->message);
        $this->assertSame([], $client->createCalls);
    }

    public function testMissingDeploymentFailsWithoutCreatingJob(): void
    {
        $client = $this->client();
        $client->deployment = null;

        $result = $this->execute($client);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('KUBERNETES_NOT_FOUND', $result->message);
        $this->assertSame([], $client->createCalls);
    }

    public function testAttemptTwoUsesDistinctJobName(): void
    {
        $client = $this->client();
        $client->jobStates = [['status' => ['succeeded' => 1]]];

        $this->execute($client, attempt: 2);

        $this->assertSame('sj-abc123-a2', $client->createCalls[0]['metadata']['name']);
    }

    /**
     * @param array<string, mixed>|null $k8sSpec
     */
    private function execute(
        FakeKubernetesClient $client,
        ?RecordingKubernetesHook $hook = null,
        ?KubernetesExecutorOptions $options = null,
        int $timeout = 60,
        int $attempt = 1,
        ?array $k8sSpec = null,
    ): ExecutionResult {
        $definition = TaskDefinition::fromArray([
            'cron_task_id' => 100,
            'cron_name' => 'k8s-demo',
            'expression' => '60',
            'exec_type' => 3,
            'command' => 'production/order-service /app/bin/task',
            'timeout' => $timeout,
            'k8s_spec' => $k8sSpec ?? [
                'namespace' => 'production',
                'deployment' => 'order-service',
                'container' => 'order-service',
                'command' => ['/app/bin/task'],
            ],
        ]);
        $snapshot = new ExecutionSnapshot('id:100', 'abc123', $definition, 1000, $attempt);

        $executor = new KubernetesExecutor(
            client: $client,
            hook: $hook ?? new RecordingKubernetesHook(),
            options: $options ?? new KubernetesExecutorOptions(pollIntervalSeconds: 1),
        );

        return $executor->run($snapshot);
    }

    private function client(): FakeKubernetesClient
    {
        $client = new FakeKubernetesClient();
        $client->deployment = [
            'spec' => [
                'selector' => ['matchLabels' => ['app' => 'order-service']],
                'template' => [
                    'metadata' => ['labels' => ['app' => 'order-service']],
                    'spec' => [
                        'containers' => [
                            ['name' => 'order-service', 'image' => 'registry.test/order-service:v1.8.7'],
                        ],
                    ],
                ],
            ],
        ];

        return $client;
    }
}

/**
 * 记录调用顺序的 Hook，并可脚本化 stopSignal 的返回序列。
 */
final class RecordingKubernetesHook implements KubernetesExecutionHookInterface
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<string> 依次返回；用尽后返回 STOP_NONE */
    public array $signals = [];

    /** @var array<string, mixed> */
    public array $finishedMeta = [];

    public int $executionId = 90001;

    public function onJobPlanned(ExecutionSnapshot $snapshot, string $namespace, string $jobName): int
    {
        $this->events[] = 'planned:' . $jobName;

        return $this->executionId;
    }

    public function onJobCreated(
        ExecutionSnapshot $snapshot,
        string $namespace,
        string $jobName,
        string $uid,
        callable $terminator,
    ): void {
        $this->events[] = 'created:' . $jobName;
    }

    public function stopSignal(ExecutionSnapshot $snapshot): string
    {
        return array_shift($this->signals) ?? self::STOP_NONE;
    }

    public function onJobFinished(ExecutionSnapshot $snapshot, array $meta): void
    {
        $this->events[] = 'finished';
        $this->finishedMeta = $meta;
    }
}
