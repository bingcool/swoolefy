<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 人工介入（HITL）暂停节点 —— 返回 WAITING，等待 WorkflowEngine::resume()。
 *
 * 典型流程（contract_review）：
 *   generate_contract → legal_review (WAITING)
 *   → resume(['approved' => true]) → 条件边 → publish / revise_contract
 *
 * 配置项：
 *   assignee    — 审批负责人/角色（供 pause/tasks API 过滤）
 *   title       — 审批标题
 *   payloadKeys — 暴露给审批 UI 的 state.data 键列表
 *
 * @see swoolefyAI.md §4.5
 */
final class PauseNode extends AbstractNode
{
    /** @param array<string, mixed> $options 暂停节点配置 */
    public function __construct(
        string $nodeId,
        private readonly array $options = [],
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} 返回 WAITING，附带审批上下文快照。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return NodeExecutionResult::waiting([
            'assignee' => $this->options['assignee'] ?? null,
            'title' => $this->options['title'] ?? null,
            'payloadKeys' => $this->options['payloadKeys'] ?? [],
            'payload' => $this->buildPayload($state),
            'runId' => $ctx->runId,
            'nodeId' => $this->nodeId,
        ]);
    }

    /** {@inheritdoc} 将人工 feedback 合并进 state.data.feedback。 */
    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
        $state->mergeData(['feedback' => $feedback]);
    }

    /**
     * 按 payloadKeys 从 state.data 提取审批 UI 所需字段。
     *
     * @return array<string, mixed>
     */
    private function buildPayload(WorkflowState $state): array
    {
        $keys = $this->options['payloadKeys'] ?? [];
        if (!is_array($keys) || $keys === []) {
            return [];
        }

        $payload = [];
        foreach ($keys as $key) {
            if (is_string($key) && array_key_exists($key, $state->data)) {
                $payload[$key] = $state->get($key);
            }
        }

        return $payload;
    }
}
