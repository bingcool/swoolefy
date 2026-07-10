<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\NodeStatus;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Throwable;

/**
 * 工作流节点抽象基类（模板方法模式）。
 *
 * 引擎必须调用 {@see run()}，不要直接调用 execute()，以保证钩子顺序：
 *
 *   beforeExecute → execute → (onPause|onRetry|onFail) → afterExecute（仅 SUCCESS）
 *
 * HITL 恢复路径：
 *   Engine::resume() → resume() → onResume → 重新求值条件边
 *
 * 横切能力（Metrics、Tracing）由 WorkflowEngine 通过 PluginManager 触发，
 * 业务钩子写在本类，插件钩子写在 Plugin 中。
 *
 * @see docs/SwoolefyAI.md §4.2、NodeLifecycle
 */
abstract class AbstractNode implements NodeInterface
{
    public function __construct(protected readonly string $nodeId)
    {
    }

    /** {@inheritdoc} */
    public function id(): string
    {
        return $this->nodeId;
    }

    /**
     * 编排完整生命周期，子类只需实现 execute()。
     * final 禁止子类覆盖，避免破坏钩子顺序。
     */
    final public function run(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $this->beforeExecute($ctx, $state);

        try {
            $result = $this->execute($ctx, $state);

            match ($result->status) {
                NodeStatus::WAITING => $this->onPause($ctx, $state, $result),
                NodeStatus::RETRY => $this->onRetry($ctx, $state, $ctx->attempt(), $result->error),
                NodeStatus::FAILED => $this->onFail($ctx, $state, $result->error),
                default => null,
            };

            if ($result->status === NodeStatus::SUCCESS) {
                $this->afterExecute($ctx, $state, $result);
            }

            return $result;
        } catch (WorkflowException $e) {
            $this->onFail($ctx, $state, $e);
            throw $e;
        } catch (Throwable $e) {
            $this->onFail($ctx, $state, $e);
            throw $e;
        }
    }

    /**
     * HITL 恢复入口，由 WorkflowEngine::resume() 调用。
     *
     * @param array<string, mixed> $feedback 人工反馈，已合并进 state.data
     */
    final public function resume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
        $this->onResume($ctx, $state, $feedback);
    }

    /** {@inheritdoc} 默认空实现，子类按需覆盖。 */
    public function beforeExecute(RunContext $ctx, WorkflowState $state): void
    {
    }

    /** {@inheritdoc} 子类必须实现的核心逻辑。 */
    abstract public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult;

    /** {@inheritdoc} */
    public function afterExecute(RunContext $ctx, WorkflowState $state, NodeExecutionResult $result): void
    {
    }

    /** {@inheritdoc} */
    public function onRetry(RunContext $ctx, WorkflowState $state, int $attempt, ?Throwable $e): void
    {
    }

    /** {@inheritdoc} */
    public function onTimeout(RunContext $ctx, WorkflowState $state): void
    {
    }

    /** {@inheritdoc} */
    public function onPause(RunContext $ctx, WorkflowState $state, NodeExecutionResult $result): void
    {
    }

    /** {@inheritdoc} */
    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
    }

    /** {@inheritdoc} */
    public function onFail(RunContext $ctx, WorkflowState $state, ?Throwable $e): void
    {
    }

    /** {@inheritdoc} Saga 补偿，拓扑逆序调用（Phase 4）。 */
    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
    }
}
