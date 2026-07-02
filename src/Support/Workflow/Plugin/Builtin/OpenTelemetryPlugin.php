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
 * OpenTelemetry 风格追踪插件 —— 收集 span 并可通过 exporter 导出。
 *
 * 生产对接 CurlProxy OTel 或 OTLP HTTP；默认内存收集供调试。
 */
final class OpenTelemetryPlugin implements WorkflowPluginInterface
{
    /** @var list<array<string, mixed>> */
    private array $spans = [];

    /** @param (callable(array<string, mixed>): void)|null $exporter */
    public function __construct(
        private $exporter = null,
    ) {
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'opentelemetry';
    }

    /** @return list<array<string, mixed>> */
    public function spans(): array
    {
        return $this->spans;
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $this->record('workflow.run', [
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'inputKeys' => array_keys($input),
            ]);
        });

        $registry->onRunComplete(function (WorkflowRun $run): void {
            $this->record('workflow.run.complete', [
                'runId' => $run->runId,
                'status' => $run->status->value,
            ]);
        });

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

    /** @param array<string, mixed> $attributes */
    private function record(string $name, array $attributes): void
    {
        $span = [
            'name' => $name,
            'attributes' => $attributes,
            'timestamp' => microtime(true),
        ];
        $this->spans[] = $span;

        if ($this->exporter !== null) {
            ($this->exporter)($span);
        }
    }
}
