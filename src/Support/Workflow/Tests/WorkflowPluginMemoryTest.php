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

/**
 * TracingPlugin / MetricsPlugin / OpenTelemetryPlugin 内存上限回归。
 *
 * 验证：按已完成 run 清理 + FIFO 硬上限，避免 Worker 长驻涨内存。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowPluginMemoryTest.php
 */

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\NodeStatus;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\Builtin\MetricsPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\OpenTelemetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeCompiled(): CompiledWorkflow
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

function makeRun(string $runId, CompiledWorkflow $compiled): WorkflowRun
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

function makeNode(string $id): NodeInterface
{
    return new class ($id) implements NodeInterface {
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

        public function onFail($ctx, $state, ?\Throwable $e): void
        {
        }

        public function compensate($ctx, $state): void
        {
        }
    };
}

/**
 * 模拟一次完整 run：start → node before/after → complete。
 */
function fireOneRun(PluginManager $manager, string $runId, CompiledWorkflow $compiled, string $nodeId = 'n1'): void
{
    $run = makeRun($runId, $compiled);
    $ctx = new RunContext($runId, $compiled);
    $node = makeNode($nodeId);
    $state = WorkflowState::fromInput([]);
    $result = new NodeExecutionResult(NodeStatus::SUCCESS, null, [], ['latencyMs' => 12]);

    $manager->fireRunStart($run, ['x' => 1]);
    $manager->fireNodeBefore($ctx, $node, $state);
    $manager->fireNodeAfter($ctx, $node, $state, $result);
    $manager->fireRunComplete($run);
}

function testTracingRetainsOnlyRecentCompletedRuns(): void
{
    $tracing = new TracingPlugin(maxSpans: 2000, retainCompletedRuns: 2);
    $manager = new PluginManager([$tracing]);
    $compiled = makeCompiled();

    fireOneRun($manager, 'run-1', $compiled);
    fireOneRun($manager, 'run-2', $compiled);
    fireOneRun($manager, 'run-3', $compiled);

    $runIds = array_unique(array_map(
        static fn (array $span): string => (string) ($span['runId'] ?? ''),
        $tracing->spans(),
    ));
    sort($runIds);

    assertTrue(!in_array('run-1', $runIds, true), 'oldest completed run spans dropped');
    assertTrue(in_array('run-2', $runIds, true), 'retain run-2');
    assertTrue(in_array('run-3', $runIds, true), 'retain run-3');
    assertTrue(count($runIds) === 2, 'only 2 completed runs retained');
}

function testTracingFifoMaxSpans(): void
{
    // retainCompletedRuns 很大，只靠 maxSpans 截断
    $tracing = new TracingPlugin(maxSpans: 5, retainCompletedRuns: 100);
    $manager = new PluginManager([$tracing]);
    $compiled = makeCompiled();

    // 每次 run 产生 4 个 span（start + node.before + node.after + complete）
    fireOneRun($manager, 'run-a', $compiled);
    fireOneRun($manager, 'run-b', $compiled);

    assertTrue(count($tracing->spans()) <= 5, 'spans capped by maxSpans');
}

function testTracingRetainZeroClearsOnComplete(): void
{
    $tracing = new TracingPlugin(maxSpans: 2000, retainCompletedRuns: 0);
    $manager = new PluginManager([$tracing]);
    $compiled = makeCompiled();

    fireOneRun($manager, 'run-z', $compiled);
    assertTrue($tracing->spans() === [], 'retain=0 clears spans after complete');
}

function testMetricsLatencyRetainAndCap(): void
{
    $metrics = new MetricsPlugin(maxLatencySamples: 3, retainCompletedRuns: 1);
    $manager = new PluginManager([$metrics]);
    $compiled = makeCompiled();

    fireOneRun($manager, 'm-1', $compiled, 'n1');
    fireOneRun($manager, 'm-2', $compiled, 'n2');

    $snapshot = $metrics->snapshot();
    assertTrue(($snapshot['runs'] ?? 0) === 2, 'run counters retained');
    assertTrue(($snapshot['nodes'] ?? 0) === 2, 'node counters retained');

    /** @var list<array<string, mixed>> $samples */
    $samples = $snapshot['node_latency_ms'];
    $sampleRunIds = array_unique(array_map(
        static fn (array $row): string => (string) ($row['runId'] ?? ''),
        $samples,
    ));

    assertTrue(!in_array('m-1', $sampleRunIds, true), 'old run latency dropped by retain window');
    assertTrue(in_array('m-2', $sampleRunIds, true), 'latest run latency kept');
    assertTrue(count($samples) <= 3, 'latency samples capped');
}

function testOpenTelemetryRetainWindow(): void
{
    $otel = new OpenTelemetryPlugin(exporter: null, maxSpans: 2000, retainCompletedRuns: 1);
    $manager = new PluginManager([$otel]);
    $compiled = makeCompiled();

    fireOneRun($manager, 'o-1', $compiled);
    fireOneRun($manager, 'o-2', $compiled);

    $runIds = array_unique(array_map(
        static fn (array $span): string => (string) ($span['attributes']['runId'] ?? ''),
        $otel->spans(),
    ));

    assertTrue(!in_array('o-1', $runIds, true), 'otel dropped old run');
    assertTrue(in_array('o-2', $runIds, true), 'otel kept latest run');
}

$tests = [
    'tracing retain recent runs' => 'testTracingRetainsOnlyRecentCompletedRuns',
    'tracing fifo max spans' => 'testTracingFifoMaxSpans',
    'tracing retain zero' => 'testTracingRetainZeroClearsOnComplete',
    'metrics latency retain and cap' => 'testMetricsLatencyRetainAndCap',
    'otel retain window' => 'testOpenTelemetryRetainWindow',
];

foreach ($tests as $label => $fn) {
    $fn();
    echo "[OK] {$label}\n";
}

echo "All plugin memory tests passed.\n";
