<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\NodeInterface;

/**
 * 节点重试执行器 —— 统一退避策略，供 {@see WorkflowEngine} 与 {@see RetryPlugin} 复用。
 */
final class RetryExecutor
{
    /**
     * 执行节点并在 RETRY 时退避重试，直至 SUCCESS / WAITING / FAILED / 超出 maxAttempts。
     */
    public function execute(
        NodeInterface $node,
        RunContext $ctx,
        \Swoolefy\Support\Workflow\State\WorkflowState $state,
        callable $runner,
        RetryPolicy $defaultPolicy,
    ): NodeExecutionResult {
        $policy = $defaultPolicy;
        $attempt = $ctx->attempt;

        while (true) {
            /** @var NodeExecutionResult $result */
            $result = $runner($node, $ctx, $state);

            if ($result->status !== NodeStatus::RETRY) {
                return $result;
            }

            $policy = $result->retry ?? $policy;
            if ($attempt >= $policy->maxAttempts) {
                return NodeExecutionResult::failed(
                    new WorkflowException("Node {$node->id()} exceeded retry attempts ({$policy->maxAttempts})"),
                );
            }

            $delayMs = (int) ($policy->delayMs * ($policy->backoffMultiplier ** ($attempt - 1)));
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $attempt++;
            $ctx = new RunContext($ctx->runId, $ctx->compiled, $attempt, $ctx->meta);
        }
    }
}
