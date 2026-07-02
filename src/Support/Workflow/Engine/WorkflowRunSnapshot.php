<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * WorkflowRun Redis 持久化 DTO —— 不含 CompiledWorkflow（按 workflowId 从 Registry 恢复）。
 */
final class WorkflowRunSnapshot
{
    /**
     * @param list<string> $executedNodeIds
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $workflowId,
        public readonly string $version,
        public readonly string $status,
        public readonly array $stateArray,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?string $currentNodeId = null,
        public readonly ?string $pauseNodeId = null,
        public readonly ?string $error = null,
        public readonly ?string $lastRoutedEdge = null,
        public readonly array $executedNodeIds = [],
    ) {
    }

    public static function fromRun(WorkflowRun $run): self
    {
        return new self(
            runId: $run->runId,
            workflowId: $run->compiled->workflowId(),
            version: $run->compiled->version(),
            status: $run->status->value,
            stateArray: $run->state->toArray(),
            createdAt: $run->createdAt,
            updatedAt: $run->updatedAt,
            currentNodeId: $run->currentNodeId,
            pauseNodeId: $run->pauseNodeId,
            error: $run->error,
            lastRoutedEdge: $run->lastRoutedEdge,
            executedNodeIds: $run->executedNodeIds,
        );
    }

    public function toArray(): array
    {
        return [
            'runId' => $this->runId,
            'workflowId' => $this->workflowId,
            'version' => $this->version,
            'status' => $this->status,
            'state' => $this->stateArray,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'currentNodeId' => $this->currentNodeId,
            'pauseNodeId' => $this->pauseNodeId,
            'error' => $this->error,
            'lastRoutedEdge' => $this->lastRoutedEdge,
            'executedNodeIds' => $this->executedNodeIds,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) ($payload['runId'] ?? ''),
            workflowId: (string) ($payload['workflowId'] ?? ''),
            version: (string) ($payload['version'] ?? '1.0.0'),
            status: (string) ($payload['status'] ?? RunStatus::FAILED->value),
            stateArray: is_array($payload['state'] ?? null) ? $payload['state'] : [],
            createdAt: (int) ($payload['createdAt'] ?? time()),
            updatedAt: (int) ($payload['updatedAt'] ?? time()),
            currentNodeId: isset($payload['currentNodeId']) ? (string) $payload['currentNodeId'] : null,
            pauseNodeId: isset($payload['pauseNodeId']) ? (string) $payload['pauseNodeId'] : null,
            error: isset($payload['error']) ? (string) $payload['error'] : null,
            lastRoutedEdge: isset($payload['lastRoutedEdge']) ? (string) $payload['lastRoutedEdge'] : null,
            executedNodeIds: is_array($payload['executedNodeIds'] ?? null) ? $payload['executedNodeIds'] : [],
        );
    }

    public function hydrate(WorkflowRegistry $registry): WorkflowRun
    {
        $compiled = $registry->compiled($this->workflowId);

        return new WorkflowRun(
            runId: $this->runId,
            compiled: $compiled,
            status: RunStatus::from($this->status),
            state: WorkflowState::fromArray($this->stateArray),
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            currentNodeId: $this->currentNodeId,
            pauseNodeId: $this->pauseNodeId,
            error: $this->error,
            lastRoutedEdge: $this->lastRoutedEdge,
            executedNodeIds: $this->executedNodeIds,
        );
    }
}
