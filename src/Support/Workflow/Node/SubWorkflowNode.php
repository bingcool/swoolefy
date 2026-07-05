<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * 子工作流节点 —— 同步嵌套执行另一个 workflowId。
 *
 * 配置：
 *   workflowId  — 子流程 ID（须在 WorkflowRegistry 注册）
 *   inputKey    — 从 state.data 读取子流程输入，默认 subWorkflowInput
 *   outputKey   — 子流程 data 写入 state.data 的键，默认 subWorkflowOutput
 */
final class SubWorkflowNode extends AbstractNode
{
    /** @param array<string, mixed> $config */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly SubWorkflowRunner $runner,
        private readonly WorkflowRegistry $registry,
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $workflowId = (string) ($this->config['workflowId'] ?? '');
        if ($workflowId === '') {
            throw new WorkflowException("SubWorkflowNode {$this->nodeId} requires workflowId");
        }

        $inputKey = (string) ($this->config['inputKey'] ?? 'subWorkflowInput');
        $outputKey = (string) ($this->config['outputKey'] ?? 'subWorkflowOutput');

        $input = $state->get($inputKey, []);
        if (!is_array($input)) {
            $input = ['value' => $input];
        }

        $compiled = $this->registry->compiled($workflowId);
        $subRunId = $this->runner->run($compiled, $input);
        $subRun = $this->runner->engine()->getRun($subRunId);

        $state->set($outputKey, $subRun->state->data);
        $state->set('subRunId', $subRunId);

        return NodeExecutionResult::success([
            $outputKey => $subRun->state->data,
            'subRunId' => $subRunId,
            'subWorkflowStatus' => $subRun->status->value,
        ], metrics: ['nodeType' => 'sub_workflow', 'subRunId' => $subRunId]);
    }
}
