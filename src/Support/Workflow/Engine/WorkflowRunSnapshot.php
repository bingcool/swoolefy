<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * WorkflowRun 持久化 DTO（不含 CompiledWorkflow 对象）。
 *
 * Redis / DB RunStore 仅序列化本 DTO；恢复时通过 workflowId + version
 * 从 {@see WorkflowRegistry} 重新 compile，保证拓扑与注册表一致。
 *
 * 关键字段：
 *   - workflowId / version — 多版本 Registry 索引键
 *   - status / pauseNodeId — HITL resume CAS 依据
 *   - state — WorkflowState 完整数组
 *   - executedNodeIds — Saga 补偿逆序依据
 *
 * @see DbRunStore
 * @see RedisRunStore
 */
final class WorkflowRunSnapshot
{
    /**
     * @param list<string> $executedNodeIds 已成功执行节点 ID 列表
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $workflowId,
        public readonly string $version,
        public readonly string $status,
        public readonly array $stateArray,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $currentNodeId = null,
        public readonly ?string $pauseNodeId = null,
        public readonly ?string $error = null,
        public readonly ?string $lastRoutedEdge = null,
        public readonly array $executedNodeIds = [],
    ) {
    }

    /** 从内存中的 WorkflowRun 构建可持久化快照。 */
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

    /** @return array<string, mixed> JSON 可序列化数组 */
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

    /** 从 Redis/DB payload 反序列化。 */
    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) ($payload['runId'] ?? ''),
            workflowId: (string) ($payload['workflowId'] ?? ''),
            version: (string) ($payload['version'] ?? '1.0.0'),
            status: (string) ($payload['status'] ?? RunStatus::FAILED->value),
            stateArray: is_array($payload['state'] ?? null) ? $payload['state'] : [],
            createdAt: WorkflowRunTime::normalize($payload['createdAt'] ?? null),
            updatedAt: WorkflowRunTime::normalize($payload['updatedAt'] ?? null),
            currentNodeId: isset($payload['currentNodeId']) ? (string) $payload['currentNodeId'] : null,
            pauseNodeId: isset($payload['pauseNodeId']) ? (string) $payload['pauseNodeId'] : null,
            error: isset($payload['error']) ? (string) $payload['error'] : null,
            lastRoutedEdge: isset($payload['lastRoutedEdge']) ? (string) $payload['lastRoutedEdge'] : null,
            executedNodeIds: is_array($payload['executedNodeIds'] ?? null) ? $payload['executedNodeIds'] : [],
        );
    }

    /**
     * 将快照恢复为可执行的 WorkflowRun。
     *
     * 版本校验：快照中的 version 必须在 Registry 中已注册，
     * 防止代码升级后 resume 旧 Run 时使用错误 DAG 拓扑。
     *
     * @throws WorkflowException workflowId 缺失或 version 未注册
     */
    public function hydrate(WorkflowRegistry $registry): WorkflowRun
    {
        if ($this->workflowId === '') {
            throw new WorkflowException('Cannot hydrate run: missing workflowId');
        }

        if (!$registry->hasVersion($this->workflowId, $this->version)) {
            throw new WorkflowException(
                "Cannot hydrate run {$this->runId}: workflow {$this->workflowId}@{$this->version} is not registered",
            );
        }

        $compiled = $registry->compiled($this->workflowId, $this->version);

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
