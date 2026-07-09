<?php

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
 * 追踪插件 —— Phase 1 内存 span 收集（workflow.run / node.execute）。
 *
 * ## 为什么需要内存上限
 *
 * Swoolefy 是常驻进程：WorkflowEngine + Plugin 通常在 Worker 级复用。
 * 若每次 run / node 都往 `$spans` 无限 append，Worker 内存会随请求量线性上涨，
 * 最终 OOM。本插件用两层策略从根上限制增长：
 *
 * 1. **按 run 清理（主策略）**
 *    - run.complete 后把 runId 记入 `$completedRunIds`；
 *    - 只保留最近 {@see $retainCompletedRuns} 个已完成 run 的 span；
 *    - 更早的 run 整批 `dropRun()` 删除；
 *    - `retainCompletedRuns=0` 表示 complete 后立刻清掉该 run（最省内存）。
 *
 * 2. **FIFO 硬上限（兜底）**
 *    - 进行中的 run、或 retain 窗口内 span 仍可能很多；
 *    - 总条数超过 {@see $maxSpans} 时丢弃最旧条目，保证绝对上界。
 *
 * ## span 结构示例
 *
 * ```php
 * ['type' => 'workflow.run.start', 'runId' => '...', 'workflowId' => '...', 'at' => 1710000000.12]
 * ['type' => 'node.execute.start', 'runId' => '...', 'nodeId' => 'payment', 'attempt' => 1, 'at' => ...]
 * ['type' => 'node.execute.end',   'runId' => '...', 'nodeId' => 'payment', 'status' => 'success', 'at' => ...]
 * ['type' => 'workflow.run.complete', 'runId' => '...', 'status' => 'completed', 'at' => ...]
 * ```
 *
 * Phase 3 可替换或叠加 {@see OpenTelemetryPlugin} 做外部导出。
 */
final class TracingPlugin implements WorkflowPluginInterface
{
    /**
     * 内存中的 span 列表（调试 / 单测可读）。
     *
     * @var list<array<string, mixed>>
     */
    private array $spans = [];

    /**
     * 已完成 runId 队列（按完成时间先进先出）。
     *
     * 用于「按 run 清理」：队列长度超过 retainCompletedRuns 时，
     * 弹出最旧 runId 并删除其全部 span。
     *
     * @var list<string>
     */
    private array $completedRunIds = [];

    /**
     * @param int $maxSpans            span 总数硬上限；超出后 FIFO 丢弃最旧。默认 2000
     * @param int $retainCompletedRuns 保留最近多少个「已完成」run 的 span。
     *                                 默认 10；设为 0 表示 run 一结束就清掉该 run
     */
    public function __construct(
        private readonly int $maxSpans = 2000,
        private readonly int $retainCompletedRuns = 10,
    ) {
    }

    /** 插件稳定名称，PluginManager 以此为键去重。 */
    public function name(): string
    {
        return 'tracing';
    }

    /**
     * 读取当前仍保留在内存中的 span（单测断言 / 本地调试）。
     *
     * 注意：生产长驻进程中该列表会被自动裁剪，不保证包含历史全量。
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /**
     * 手动清空全部 span 与已完成 run 队列。
     *
     * 适用：单测隔离、运维临时重置、Worker 热更新前主动释放。
     */
    public function clear(): void
    {
        $this->spans = [];
        $this->completedRunIds = [];
    }

    /**
     * 向 PluginRegistry 注册生命周期钩子。
     *
     * 钩子闭包会捕获 `$this`，因此 Plugin 实例与 Registry 同生命周期；
     * 只要本类内部数组有界，就不会因闭包导致无限涨内存。
     */
    public function register(PluginRegistry $registry): void
    {
        // Run 开始：记录 workflow.run.start
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            unset($input); // input 可能很大，span 里不存正文
            $this->append([
                'type' => 'workflow.run.start',
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'at' => microtime(true),
            ]);
        });

        // Run 结束：先记 complete span，再触发按 run 清理
        $registry->onRunComplete(function (WorkflowRun $run): void {
            $this->append([
                'type' => 'workflow.run.complete',
                'runId' => $run->runId,
                'status' => $run->status->value,
                'at' => microtime(true),
            ]);
            // 关键：把该 run 标为已完成，并淘汰超出窗口的旧 run
            $this->markRunCompleted($run->runId);
        });

        // 节点执行前
        $registry->onNodeBefore(function (RunContext $ctx, NodeInterface $node, WorkflowState $state): void {
            unset($state); // state 可能很大，不写入 span
            $this->append([
                'type' => 'node.execute.start',
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'attempt' => $ctx->attempt(),
                'at' => microtime(true),
            ]);
        });

        // 节点执行后（SUCCESS / WAITING / RETRY 等，FAILED 走 node.fail）
        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            unset($state);
            $this->append([
                'type' => 'node.execute.end',
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'status' => $result->status->value,
                'at' => microtime(true),
            ]);
        });
    }

    /**
     * 追加一条 span，并立即做 FIFO 硬上限检查。
     *
     * @param array<string, mixed> $span 必须含 runId，便于后续按 run 删除
     */
    private function append(array $span): void
    {
        $this->spans[] = $span;
        $this->trimToMax();
    }

    /**
     * 标记 run 已完成，并按 retain 窗口淘汰旧 run。
     *
     * 示例（retainCompletedRuns=2）：
     *   complete(run-1) → 队列 [run-1]
     *   complete(run-2) → 队列 [run-1, run-2]
     *   complete(run-3) → 弹出 run-1 并 dropRun，队列 [run-2, run-3]
     */
    private function markRunCompleted(string $runId): void
    {
        $this->completedRunIds[] = $runId;
        $retain = max(0, $this->retainCompletedRuns);

        // 队列过长时，从队头弹出最旧已完成 run，并删除其 span
        while (count($this->completedRunIds) > $retain) {
            $oldRunId = array_shift($this->completedRunIds);
            if (!is_string($oldRunId) || $oldRunId === '') {
                continue;
            }
            $this->dropRun($oldRunId);
        }
    }

    /**
     * 丢弃指定 runId 的全部 span。
     *
     * 进行中的其它 run、以及仍在 retain 窗口内的 run 不受影响。
     */
    private function dropRun(string $runId): void
    {
        $this->spans = array_values(array_filter(
            $this->spans,
            static fn (array $span): bool => ($span['runId'] ?? null) !== $runId,
        ));
    }

    /**
     * FIFO 硬上限：总 span 超过 maxSpans 时只保留最新的 maxSpans 条。
     *
     * 兜底场景：单个超长 run、或 retain 窗口内并发 run 很多时，
     * 仍保证内存有绝对上界。
     */
    private function trimToMax(): void
    {
        $max = max(1, $this->maxSpans);
        if (count($this->spans) <= $max) {
            return;
        }

        // 保留尾部（最新），丢弃头部（最旧）
        $this->spans = array_slice($this->spans, -$max);
    }
}
