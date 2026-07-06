<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Workflow Run API 响应格式化器。
 *
 * 默认返回安全摘要，避免 status/events 接口泄露业务输入、Agent 输出、
 * HITL feedback 等敏感 state。只有明确授权的调试视图才包含 details。
 */
final class WorkflowRunPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(WorkflowRun $run, bool $includeDetails = false): array
    {
        $payload = [
            'runId' => $run->runId,
            'workflowId' => $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            'lastRoutedEdge' => $run->lastRoutedEdge,
            'currentNodeId' => $run->currentNodeId,
            'pauseNodeId' => $run->pauseNodeId,
            'executedNodeIds' => $run->executedNodeIds,
            'hasError' => $run->error !== null && $run->error !== '',
        ];

        if (!$includeDetails) {
            return $payload;
        }

        return $payload + [
            'error' => $run->error,
            'data' => $run->state->data,
            'nodeOutputs' => $run->state->nodeOutputs,
            'agentOutputs' => $run->state->agentOutputs,
        ];
    }
}
