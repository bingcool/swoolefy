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

namespace Swoolefy\Support\Workflow\Plugin\Builtin;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * OpenTelemetry 风格追踪插件 —— 收集 span，并可选通过 exporter 导出。
 *
 * ## 定位
 *
 * - 默认：内存收集，供本地调试 / 单测；
 * - 生产：注入 `$exporter`（如 OTLP HTTP / CurlProxy OTel），每条 span 即时导出；
 * - 即使配置了 exporter，内存 `$spans` 仍会短暂保留，因此同样需要上限策略。
 *
 * ## 与 TracingPlugin 的关系
 *
 * - TracingPlugin：轻量内建 span（type/runId/at），默认随 WorkflowBootstrap 启用；
 * - OpenTelemetryPlugin：更接近 OTel 语义（name + attributes），由 `WORKFLOW_OTEL_ENABLED` 开关追加。
 * - 两者防泄漏策略一致：**按已完成 run 保留窗口 + maxSpans FIFO**。
 *
 * ## span 结构
 *
 * ```php
 * [
 *   'name' => 'workflow.node',
 *   'attributes' => ['runId' => '...', 'nodeId' => 'payment', 'status' => 'success', ...],
 *   'timestamp' => 1710000000.12,
 * ]
 * ```
 *
 * 按 run 清理时读取的是 `attributes.runId`（与 TracingPlugin 顶层 `runId` 不同）。
 */
final class OpenTelemetryPlugin implements WorkflowPluginInterface
{
    /**
     * 内存 span 缓冲（调试可读；生产依赖 exporter 时仍会短暂持有）。
     *
     * @var list<array<string, mixed>>
     */
    private array $spans = [];

    /**
     * 已完成 runId 队列，用于按 run 清理。
     *
     * @var list<string>
     */
    private array $completedRunIds = [];

    /**
     * @param (callable(array<string, mixed>): void)|null $exporter
     *        每写入一条 span 时回调；null 表示仅内存收集。
     *        回调应尽量轻量、不抛异常，避免拖垮工作流主路径。
     * @param int $maxSpans            内存 span 硬上限（FIFO）。默认 2000
     * @param int $retainCompletedRuns 保留最近多少个已完成 run。默认 10；0=complete 后立即清掉
     */
    public function __construct(
        private $exporter = null,
        private readonly int $maxSpans = 2000,
        private readonly int $retainCompletedRuns = 10,
    ) {
    }

    /** 插件稳定名称。 */
    public function name(): string
    {
        return 'opentelemetry';
    }

    /**
     * 当前仍保留在内存中的 span 列表。
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * 手动清空内存 span 与已完成 run 队列。
     *
     * 不影响已经通过 exporter 发出去的数据。
     */
    public function clear(): void
    {
        $this->spans = [];
        $this->completedRunIds = [];
    }

    /**
     * 注册 Run / Node 钩子，写入 OTel 风格 span。
     */
    public function register(PluginRegistry $registry): void
    {
        // Run 开始：只记 input 的 key 列表，避免把大 payload 塞进 span
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $this->record('workflow.run', [
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'inputKeys' => array_keys($input),
            ]);
        });

        // Run 结束：写 complete span，再按窗口清理旧 run
        $registry->onRunComplete(function (WorkflowRun $run): void {
            $this->record('workflow.run.complete', [
                'runId' => $run->runId,
                'status' => $run->status->value,
            ]);
            $this->markRunCompleted($run->runId);
        });

        // 节点执行后：记录状态、延迟、重试次数
        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            unset($state);
            $this->record('workflow.node', [
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'status' => $result->status->value,
                'latencyMs' => $result->metrics['latencyMs'] ?? 0,
                'attempt' => $ctx->attempt(),
            ]);
        });
    }

    /**
     * 组装一条 span：写入内存 → FIFO 截断 → 可选 exporter 导出。
     *
     * @param array<string, mixed> $attributes 须含 runId，供按 run 清理匹配
     */
    private function record(string $name, array $attributes): void
    {
        $span = [
            'name' => $name,
            'attributes' => $attributes,
            'timestamp' => microtime(true),
        ];
        $this->spans[] = $span;
        $this->trimToMax();

        // 导出失败不应拖垮主流程；调用方 exporter 自行吞异常或异步投递
        if ($this->exporter !== null) {
            ($this->exporter)($span);
        }
    }

    /**
     * 标记 run 已完成，并淘汰超出 retain 窗口的旧 run 内存 span。
     *
     * 已通过 exporter 发出的数据不受影响；这里只释放本地 `$spans`。
     */
    private function markRunCompleted(string $runId): void
    {
        $this->completedRunIds[] = $runId;
        $retain = max(0, $this->retainCompletedRuns);

        while (count($this->completedRunIds) > $retain) {
            $oldRunId = array_shift($this->completedRunIds);
            if (!is_string($oldRunId) || $oldRunId === '') {
                continue;
            }
            $this->dropRun($oldRunId);
        }
    }

    /**
     * 按 attributes.runId 删除指定 run 的内存 span。
     *
     * 注意字段路径与 TracingPlugin 不同：OTel span 的 runId 在 attributes 内。
     */
    private function dropRun(string $runId): void
    {
        $this->spans = array_values(array_filter(
            $this->spans,
            static fn (array $span): bool => ($span['attributes']['runId'] ?? null) !== $runId,
        ));
    }

    /**
     * FIFO 硬上限：超过 maxSpans 时只保留最新条目。
     */
    private function trimToMax(): void
    {
        $max = max(1, $this->maxSpans);
        if (count($this->spans) <= $max) {
            return;
        }

        $this->spans = array_slice($this->spans, -$max);
    }
}
