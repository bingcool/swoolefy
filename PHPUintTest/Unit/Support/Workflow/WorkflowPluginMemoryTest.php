<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Workflow;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\NodeStatus;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\Builtin\MetricsPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\OpenTelemetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use PHPUintTest\TestCase;
use Throwable;

/**
 * TracingPlugin / MetricsPlugin / OpenTelemetryPlugin 内存上限回归。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | TracingPlugin | retainCompletedRuns 滑动窗口、maxSpans FIFO、retain=0 即清 |
 * | MetricsPlugin | 计数保留 + node_latency_ms 窗口与硬上限 |
 * | OpenTelemetryPlugin | retainCompletedRuns 仅保留最近 run 的 span |
 * | PluginManager | 同名插件 add 替换而非累积 hooks |
 *
 * 验证按已完成 run 清理 + FIFO 硬上限，避免 Worker 长驻涨内存。
 */
final class WorkflowPluginMemoryTest extends TestCase
{
    /**
     * 构造最小 CompiledWorkflow 夹具（无实际节点图），供插件钩子测试使用。
     */
    private function makeCompiled(): CompiledWorkflow
    {
        return new CompiledWorkflow(
            workflowId: 'mem_test',
            version: '1',
            nodes: [],
            fixedEdges: [],
            conditionalGroups: [],
            entryNodes: [],
            warnings: [],
            schemas: [],
            plugins: [],
            metadata: [],
        );
    }

    /**
     * 构造 COMPLETED 状态的 WorkflowRun 夹具。
     */
    private function makeRun(string $runId, CompiledWorkflow $compiled): WorkflowRun
    {
        $now = date('c');

        return new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: RunStatus::COMPLETED,
            state: WorkflowState::fromInput([]),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * 构造带 latencyMs 的成功 NodeInterface 夹具。
     */
    private function makeNode(string $id): NodeInterface
    {
        return new LatencyStubNode($id);
    }

    /**
     * 模拟一次完整 run 的插件生命周期：start → node before/after → complete。
     *
     * 不经过真实 WorkflowEngine，直接驱动 PluginManager 钩子。
     */
    private function fireOneRun(PluginManager $manager, string $runId, CompiledWorkflow $compiled, string $nodeId = 'n1'): void
    {
        $run = $this->makeRun($runId, $compiled);
        $ctx = new RunContext($runId, $compiled);
        $node = $this->makeNode($nodeId);
        $state = WorkflowState::fromInput([]);
        $result = new NodeExecutionResult(NodeStatus::SUCCESS, null, [], ['latencyMs' => 12]);

        $manager->fireRunStart($run, ['x' => 1]);
        $manager->fireNodeBefore($ctx, $node, $state);
        $manager->fireNodeAfter($ctx, $node, $state, $result);
        $manager->fireRunComplete($run);
    }

    /**
     * 验证：retainCompletedRuns=2 时仅保留最近 2 个已完成 run 的 span，最早 run-1 被丢弃。
     *
     * 长驻 Worker 须按 run 滑动清理 trace 缓冲。
     */
    public function testTracingRetainsOnlyRecentCompletedRuns(): void
    {
        $tracing = new TracingPlugin(maxSpans: 2000, retainCompletedRuns: 2);
        $manager = new PluginManager([$tracing]);
        $compiled = $this->makeCompiled();

        $this->fireOneRun($manager, 'run-1', $compiled);
        $this->fireOneRun($manager, 'run-2', $compiled);
        $this->fireOneRun($manager, 'run-3', $compiled);

        $runIds = array_unique(array_map(
            static fn (array $span): string => (string) ($span['runId'] ?? ''),
            $tracing->spans(),
        ));
        sort($runIds);

        $this->assertTrue(!in_array('run-1', $runIds, true), 'oldest completed run spans dropped');
        $this->assertTrue(in_array('run-2', $runIds, true), 'retain run-2');
        $this->assertTrue(in_array('run-3', $runIds, true), 'retain run-3');
        $this->assertTrue(count($runIds) === 2, 'only 2 completed runs retained');
    }

    /**
     * 验证：retainCompletedRuns 很大时，span 总数仍受 maxSpans FIFO 硬上限截断。
     *
     * 双重保护：按 run 清理 + 全局 span 条数上限。
     */
    public function testTracingFifoMaxSpans(): void
    {
        // retainCompletedRuns 很大，只靠 maxSpans 截断
        $tracing = new TracingPlugin(maxSpans: 5, retainCompletedRuns: 100);
        $manager = new PluginManager([$tracing]);
        $compiled = $this->makeCompiled();

        // 每次 run 产生 4 个 span（start + node.before + node.after + complete）
        $this->fireOneRun($manager, 'run-a', $compiled);
        $this->fireOneRun($manager, 'run-b', $compiled);

        $this->assertTrue(count($tracing->spans()) <= 5, 'spans capped by maxSpans');
    }

    /**
     * 验证：retainCompletedRuns=0 时 run 完成后立即清空所有 span。
     *
     * 最激进内存策略：不保留任何历史 trace。
     */
    public function testTracingRetainZeroClearsOnComplete(): void
    {
        $tracing = new TracingPlugin(maxSpans: 2000, retainCompletedRuns: 0);
        $manager = new PluginManager([$tracing]);
        $compiled = $this->makeCompiled();

        $this->fireOneRun($manager, 'run-z', $compiled);
        $this->assertTrue($tracing->spans() === [], 'retain=0 clears spans after complete');
    }

    /**
     * 验证：MetricsPlugin 保留 run/node 计数，但 node_latency_ms 按 retainCompletedRuns 丢弃旧 run 且受 maxLatencySamples 上限。
     *
     * 指标计数与延迟样本采用不同保留策略。
     */
    public function testMetricsLatencyRetainAndCap(): void
    {
        $metrics = new MetricsPlugin(maxLatencySamples: 3, retainCompletedRuns: 1);
        $manager = new PluginManager([$metrics]);
        $compiled = $this->makeCompiled();

        $this->fireOneRun($manager, 'm-1', $compiled, 'n1');
        $this->fireOneRun($manager, 'm-2', $compiled, 'n2');

        $snapshot = $metrics->snapshot();
        $this->assertTrue(($snapshot['runs'] ?? 0) === 2, 'run counters retained');
        $this->assertTrue(($snapshot['nodes'] ?? 0) === 2, 'node counters retained');

        /** @var list<array<string, mixed>> $samples */
        $samples = $snapshot['node_latency_ms'];
        $sampleRunIds = array_unique(array_map(
            static fn (array $row): string => (string) ($row['runId'] ?? ''),
            $samples,
        ));

        $this->assertTrue(!in_array('m-1', $sampleRunIds, true), 'old run latency dropped by retain window');
        $this->assertTrue(in_array('m-2', $sampleRunIds, true), 'latest run latency kept');
        $this->assertTrue(count($samples) <= 3, 'latency samples capped');
    }

    /**
     * 验证：OpenTelemetryPlugin retainCompletedRuns=1 时仅保留最近 run 的 span。
     *
     * 与 TracingPlugin 类似的滑动窗口，适配 OTel 导出缓冲。
     */
    public function testOpenTelemetryRetainWindow(): void
    {
        $otel = new OpenTelemetryPlugin(exporter: null, maxSpans: 2000, retainCompletedRuns: 1);
        $manager = new PluginManager([$otel]);
        $compiled = $this->makeCompiled();

        $this->fireOneRun($manager, 'o-1', $compiled);
        $this->fireOneRun($manager, 'o-2', $compiled);

        $runIds = array_unique(array_map(
            static fn (array $span): string => (string) ($span['attributes']['runId'] ?? ''),
            $otel->spans(),
        ));

        $this->assertTrue(!in_array('o-1', $runIds, true), 'otel dropped old run');
        $this->assertTrue(in_array('o-2', $runIds, true), 'otel kept latest run');
    }

    /**
     * 验证：PluginManager::add 同名插件时替换实例，旧实例不再接收 hooks，registry 仅一条 run.start。
     *
     * 防止热重载或重复注册导致双倍限流/双倍钩子。
     */
    public function testPluginManagerReplaceSameNameDoesNotDuplicateHooks(): void
    {
        $first = RateLimitPlugin::make(maxConcurrent: 10);
        $second = RateLimitPlugin::make(maxConcurrent: 10);
        $manager = new PluginManager([$first]);
        $manager->add($second); // 同名 rate_limit 应替换，而非累积 hooks

        $compiled = $this->makeCompiled();
        $run = $this->makeRun('rl-dup', $compiled);
        $manager->fireRunStart($run, []);

        $this->assertTrue($first->activeRuns() === 0, 'replaced plugin instance must not receive hooks');
        $this->assertTrue($second->activeRuns() === 1, 'only latest plugin instance holds the slot');

        $manager->fireRunComplete($run);
        $this->assertTrue($second->activeRuns() === 0, 'single complete releases once');
        $this->assertTrue(count($manager->registry()->hooks('run.start')) === 1, 'exactly one run.start hook');
    }
}

/**
 * 带 latencyMs 的 NodeInterface 测试夹具。
 */
final class LatencyStubNode implements NodeInterface
{
    public function __construct(private readonly string $nodeId)
    {
    }

    public function id(): string
    {
        return $this->nodeId;
    }

    public function beforeExecute($ctx, $state): void
    {
    }

    public function execute($ctx, $state): NodeExecutionResult
    {
        return NodeExecutionResult::success(null, [], ['latencyMs' => 5]);
    }

    public function afterExecute($ctx, $state, $result): void
    {
    }

    public function onRetry($ctx, $state, int $attempt, ?Throwable $e): void
    {
    }

    public function onTimeout($ctx, $state): void
    {
    }

    public function onPause($ctx, $state, $result): void
    {
    }

    public function onResume($ctx, $state, array $feedback): void
    {
    }

    public function onFail($ctx, $state, ?Throwable $e): void
    {
    }

    public function compensate($ctx, $state): void
    {
    }
}
