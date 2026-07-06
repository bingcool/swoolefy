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
 */
final class MetricsPlugin implements WorkflowPluginInterface
{
    /** @var array<string, mixed> */
    private array $metrics = [
        'runs' => 0,
        'nodes' => 0,
        'node_latency_ms' => [],
        'status_counts' => [],
    ];

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'metrics';
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->metrics;
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $this->metrics['runs']++;
        });

        $registry->onRunComplete(function (WorkflowRun $run): void {
            $status = $run->status->value;
            $this->metrics['status_counts'][$status] = ($this->metrics['status_counts'][$status] ?? 0) + 1;
        });

        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            $this->metrics['nodes']++;
            $latency = (int) ($result->metrics['latencyMs'] ?? 0);
            $this->metrics['node_latency_ms'][] = [
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'latencyMs' => $latency,
                'status' => $result->status->value,
            ];
        });
    }
}
