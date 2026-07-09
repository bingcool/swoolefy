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
 * 指标插件 —— Phase 2 内存计数（run / node 延迟、状态分布）。
 *
 * ## 数据结构
 *
 * ```php
 * [
 *   'runs' => 12,                          // 累计 Run 次数（有界整数，可长期保留）
 *   'nodes' => 48,                         // 累计节点执行次数
 *   'status_counts' => ['completed' => 10], // 按 RunStatus 聚合（键空间≈枚举数，有界）
 *   'node_latency_ms' => [                 // 延迟明细（会增长，必须裁剪）
 *     ['runId' => '...', 'nodeId' => 'payment', 'latencyMs' => 12, 'status' => 'success'],
 *   ],
 * ]
 * ```
 *
 * ## 为什么只裁剪 node_latency_ms
 *
 * - `runs` / `nodes` / `status_counts` 是聚合计数，内存占用几乎恒定，适合长期观测；
 * - `node_latency_ms` 每执行一个节点就 append 一行，是长驻 Worker 的主要泄漏源。
 *
 * ## 两层防泄漏策略（仅针对延迟明细）
 *
 * 1. **按 run 清理**：run.complete 后只保留最近 {@see $retainCompletedRuns} 个已完成 run 的明细；
 * 2. **FIFO 硬上限**：明细条数超过 {@see $maxLatencySamples} 时丢弃最旧。
 *
 * `retainCompletedRuns=0` 表示 run 一结束就清掉该 run 的延迟明细。
 */
final class MetricsPlugin implements WorkflowPluginInterface
{
    /**
     * 指标快照。
     *
     * @var array{
     *     runs: int,
     *     nodes: int,
     *     node_latency_ms: list<array<string, mixed>>,
     *     status_counts: array<string, int>
     * }
     */
    private array $metrics = [
        'runs' => 0,
        'nodes' => 0,
        'node_latency_ms' => [],
        'status_counts' => [],
    ];

    /**
     * 已完成 runId 队列（按完成时间 FIFO），用于按 run 清理延迟明细。
     *
     * @var list<string>
     */
    private array $completedRunIds = [];

    /**
     * @param int $maxLatencySamples   延迟明细硬上限（FIFO）。默认 1000
     * @param int $retainCompletedRuns 保留最近多少个已完成 run 的延迟明细。
     *                                 默认 10；0 表示 complete 后立即清掉该 run 明细
     */
    public function __construct(
        private readonly int $maxLatencySamples = 1000,
        private readonly int $retainCompletedRuns = 10,
    ) {
    }

    /** 插件稳定名称。 */
    public function name(): string
    {
        return 'metrics';
    }

    /**
     * 返回当前指标快照（含可能已被裁剪的延迟明细）。
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->metrics;
    }

    /**
     * 手动清空全部计数与延迟明细。
     *
     * 适用：单测隔离、运维重置。注意：会把 runs/nodes 等累计值一并归零。
     */
    public function clear(): void
    {
        $this->metrics = [
            'runs' => 0,
            'nodes' => 0,
            'node_latency_ms' => [],
            'status_counts' => [],
        ];
        $this->completedRunIds = [];
    }

    /**
     * 注册 Run / Node 生命周期钩子，采集计数与延迟。
     */
    public function register(PluginRegistry $registry): void
    {
        // Run 开始：累计 runs（整数，不构成泄漏）
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            unset($run, $input);
            $this->metrics['runs']++;
        });

        // Run 结束：按状态聚合 + 触发按 run 清理延迟明细
        $registry->onRunComplete(function (WorkflowRun $run): void {
            $status = $run->status->value;
            // status_counts 键空间 ≈ RunStatus 枚举，天然有界
            $this->metrics['status_counts'][$status] = ($this->metrics['status_counts'][$status] ?? 0) + 1;
            $this->markRunCompleted($run->runId);
        });

        // 节点执行后：累计 nodes，并追加一条延迟明细（需裁剪）
        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            unset($state);
            $this->metrics['nodes']++;
            $latency = (int) ($result->metrics['latencyMs'] ?? 0);
            $this->metrics['node_latency_ms'][] = [
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'latencyMs' => $latency,
                'status' => $result->status->value,
            ];
            // 每次追加后立刻检查硬上限，避免单次超长 run 撑爆
            $this->trimLatencySamples();
        });
    }

    /**
     * 标记 run 已完成，并按 retain 窗口淘汰旧 run 的延迟明细。
     *
     * 注意：只删 node_latency_ms，不动 runs / nodes / status_counts。
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
            $this->dropRunLatency($oldRunId);
        }
    }

    /**
     * 删除指定 runId 在 node_latency_ms 中的全部明细行。
     */
    private function dropRunLatency(string $runId): void
    {
        /** @var list<array<string, mixed>> $samples */
        $samples = $this->metrics['node_latency_ms'];
        $this->metrics['node_latency_ms'] = array_values(array_filter(
            $samples,
            static fn (array $row): bool => ($row['runId'] ?? null) !== $runId,
        ));
    }

    /**
     * FIFO 硬上限：延迟明细超过 maxLatencySamples 时只保留最新的若干条。
     */
    private function trimLatencySamples(): void
    {
        $max = max(1, $this->maxLatencySamples);
        /** @var list<array<string, mixed>> $samples */
        $samples = $this->metrics['node_latency_ms'];
        if (count($samples) <= $max) {
            return;
        }

        $this->metrics['node_latency_ms'] = array_slice($samples, -$max);
    }
}
