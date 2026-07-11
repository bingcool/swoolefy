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

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Throwable;

/**
 * 子工作流节点 —— 同步嵌套执行另一个 workflowId。
 *
 * 配置：
 *   workflowId  — 子流程 ID（须在 WorkflowRegistry 注册）
 *   inputKey    — 从 state.data 读取子流程输入，默认 subWorkflowInput
 *   outputKey   — 子流程 data 写入 state.data 的键，默认 subWorkflowOutput
 *
 * 子 Run 状态映射到父节点结果：
 *   COMPLETED           → SUCCESS（父 DAG 继续）
 *   WAITING（HITL）     → WAITING（父 DAG 暂停，避免语义脱节）
 *   FAILED / CANCELLED  → FAILED
 *
 * HITL 恢复：先 resume 子 Run（或子 Run 已完成），再 resume 父 Run；
 * {@see onResume()} 在子仍 WAITING 时抛错，由 Engine 回滚父 Run 为 WAITING。
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

        try {
            $subRunId = $this->runner->run($compiled, $input);
        } catch (Throwable $e) {
            return NodeExecutionResult::failed(
                $e instanceof WorkflowException
                    ? $e
                    : new WorkflowException($e->getMessage(), 0, $e),
            );
        }

        $subRun = $this->runner->engine()->getRun($subRunId);

        $state->set($outputKey, $subRun->state->data);
        $state->set('subRunId', $subRunId);

        return $this->mapSubRunResult($workflowId, $subRunId, $outputKey, $subRun->status, $subRun->state->data);
    }

    /**
     * 父 Run resume：合并子流程输出；子仍 WAITING 时尝试用同一 feedback 恢复子 Run。
     *
     * {@inheritdoc}
     */
    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
        $subRunId = $state->get('subRunId');
        if (!is_string($subRunId) || $subRunId === '') {
            throw new WorkflowException("SubWorkflowNode {$this->nodeId} missing subRunId on resume");
        }

        $engine = $this->runner->engine();
        $subRun = $engine->getRun($subRunId);

        if ($subRun->status === RunStatus::WAITING) {
            $engine->resume($subRunId, $feedback);
            $subRun = $engine->getRun($subRunId);
        }

        $outputKey = (string) ($this->config['outputKey'] ?? 'subWorkflowOutput');
        $state->set($outputKey, $subRun->state->data);

        if ($subRun->status === RunStatus::WAITING) {
            throw new WorkflowException(
                "Sub-workflow {$subRunId} is still WAITING; resume the child run (or parent again after child HITL)",
            );
        }

        if ($subRun->status !== RunStatus::COMPLETED) {
            throw new WorkflowException(
                "Sub-workflow {$subRunId} ended with status {$subRun->status->value}, cannot continue parent",
            );
        }
    }

    /**
     * @param array<string, mixed> $subData
     */
    private function mapSubRunResult(
        string $workflowId,
        string $subRunId,
        string $outputKey,
        RunStatus $status,
        array $subData,
    ): NodeExecutionResult {
        $output = [
            $outputKey => $subData,
            'subRunId' => $subRunId,
            'subWorkflowStatus' => $status->value,
        ];
        $metrics = ['nodeType' => 'sub_workflow', 'subRunId' => $subRunId];

        return match ($status) {
            RunStatus::COMPLETED => NodeExecutionResult::success($output, metrics: $metrics),
            RunStatus::WAITING => NodeExecutionResult::waiting($output),
            RunStatus::FAILED,
            RunStatus::CANCELLED,
            RunStatus::COMPENSATED => NodeExecutionResult::failed(
                new WorkflowException("Sub-workflow {$workflowId} ended with status {$status->value}"),
                $output,
            ),
            default => NodeExecutionResult::failed(
                new WorkflowException("Sub-workflow {$workflowId} in unexpected status {$status->value}"),
                $output,
            ),
        };
    }
}
