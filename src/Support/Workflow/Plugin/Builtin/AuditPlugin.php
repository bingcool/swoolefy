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

use Swoolefy\Support\Workflow\Audit\AuditLogWriterInterface;
use Swoolefy\Support\Workflow\Audit\FileAuditLogWriter;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 审计插件 —— 记录 Run / Node / HITL 关键事件，供合规与排障。
 */
final class AuditPlugin implements WorkflowPluginInterface
{
    public function __construct(
        private readonly AuditLogWriterInterface $writer = new FileAuditLogWriter(),
    ) {
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'audit';
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            $this->writer->write('workflow.run.start', [
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'input' => $this->sanitize($input),
            ]);
        });

        $registry->onRunComplete(function (WorkflowRun $run): void {
            $this->writer->write('workflow.run.complete', [
                'runId' => $run->runId,
                'status' => $run->status->value,
                'error' => $run->error,
            ]);
        });

        $registry->onPause(function (WorkflowRun $run, NodeInterface $node): void {
            $this->writer->write('workflow.pause', [
                'runId' => $run->runId,
                'nodeId' => $node->id(),
            ]);
        });

        $registry->onResume(function (WorkflowRun $run, array $feedback): void {
            $this->writer->write('workflow.resume', [
                'runId' => $run->runId,
                'feedback' => $this->sanitize($feedback),
            ]);
        });

        $registry->onNodeFail(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            unset($state);
            $this->writer->write('workflow.node.fail', [
                'runId' => $ctx->runId,
                'nodeId' => $node->id(),
                'error' => $result->error?->getMessage(),
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function sanitize(array $payload): array
    {
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret/i', $key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
