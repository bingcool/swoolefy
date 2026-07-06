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
 * Phase 3 可替换或叠加 OpenTelemetryPlugin。
 */
final class TracingPlugin implements WorkflowPluginInterface
{
    /** @var list<array<string, mixed>> */
    private array $spans = [];

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'tracing';
    }

    /**
     * 获取已收集的 span 列表（单测/调试）。
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $this->spans[] = [
                'type' => 'workflow.run.start',
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'at' => microtime(true),
            ];
        });

        $registry->onRunComplete(function (WorkflowRun $run): void {
            $this->spans[] = [
                'type' => 'workflow.run.complete',
                'runId' => $run->runId,
                'status' => $run->status->value,
                'at' => microtime(true),
            ];
        });

        $registry->onNodeBefore(function (RunContext $ctx, NodeInterface $node, WorkflowState $state): void {
            $this->spans[] = [
                'type' => 'node.execute.start',
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'attempt' => $ctx->attempt(),
                'at' => microtime(true),
            ];
        });

        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            $this->spans[] = [
                'type' => 'node.execute.end',
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'status' => $result->status->value,
                'at' => microtime(true),
            ];
        });
    }
}
