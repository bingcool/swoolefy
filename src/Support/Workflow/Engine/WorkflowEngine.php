<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Throwable;

/**
 * 工作流运行时引擎 —— 管理 Run 生命周期：start / resume / cancel。
 *
 * Phase 1 执行模型（线性 DAG，单入口）：
 *   1. 取编译器计算的入口节点（入度为 0）
 *   2. 通过 {@see AbstractNode::run()} 执行节点（内部走生命周期钩子）
 *   3. SUCCESS → {@see DagScheduler::resolveNextNode()} → 下一节点或结束
 *   4. WAITING → 持久化快照并停止（HITL / PauseNode）
 *   5. FAILED → 标记 Run 失败并抛异常；若 metadata.saga=true 则逆序 compensate（Phase 4）
 *   6. RETRY → {@see executeNode()} 内退避重试
 *
 * 横切能力（指标、追踪、重试元数据）走 {@see PluginManager}，不走 EventBus。
 * EventBus 仅用于对外 SSE/WebSocket 广播。
 *
 * @see docs/swoolefyAI.md §6.1–§6.3
 */
final class WorkflowEngine
{
    /**
     * @param PluginManager                    $plugins            插件管理器
     * @param DagScheduler                     $scheduler          DAG 路由调度器
     * @param RunStoreInterface                $runStore           Run 快照存储（生产换 Redis）
     * @param WorkflowEventDispatcherInterface $events             对外事件分发
     * @param RetryPolicy                      $defaultRetryPolicy 默认节点重试策略
     */
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly DagScheduler $scheduler,
        private readonly RunStoreInterface $runStore = new InMemoryRunStore(),
        private readonly WorkflowEventDispatcherInterface $events = new NullWorkflowEventDispatcher(),
        private readonly RetryPolicy $defaultRetryPolicy = new RetryPolicy(),
        private readonly RetryExecutor $retryExecutor = new RetryExecutor(),
        /** 节点默认超时秒数，0 表示不限制。 */
        private readonly float $defaultNodeTimeoutSeconds = 0,
        /** Saga 补偿协调器（节点 FAILED + metadata.saga 时触发）。 */
        private readonly SagaCoordinator $sagaCoordinator = new SagaCoordinator(),
    ) {
    }

    /**
     * 启动新的工作流运行。
     *
     * @param array<string, mixed> $input 合并到 WorkflowState.data 的初始输入
     *
     * @return string runId，可用于 getRun / resume / cancel
     */
    public function start(CompiledWorkflow $compiled, array $input): string
    {
        $runId = $this->generateRunId();
        $state = WorkflowState::fromInput($input, $compiled->schemas());
        $now = time();

        $run = new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: RunStatus::RUNNING,
            state: $state,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->plugins->fireRunStart($run, $input);
        $this->runStore->save($run);

        try {
            $entry = $compiled->entryNodes()[0];
            $this->executeFromNode($run, $entry);
        } catch (Throwable $e) {
            $this->handleRunFailure($run, $e);
            throw $e;
        }

        if ($run->status === RunStatus::RUNNING) {
            $run->status = RunStatus::COMPLETED;
            $run->currentNodeId = null;
            $run->updatedAt = time();
            $this->runStore->save($run);
        }

        $this->plugins->fireRunComplete($run);

        return $runId;
    }

    /**
     * 恢复处于 WAITING 状态的 Run（人工审批后继续）。
     * 将 feedback 合并进 state.data，从 Pause 节点重新求值条件边。
     *
     * @param array<string, mixed> $feedback 如 ['approved' => true]
     */
    public function resume(string $runId, array $feedback): void
    {
        $run = $this->requireRun($runId);
        if ($run->status !== RunStatus::WAITING || $run->pauseNodeId === null) {
            throw new WorkflowException("Run {$runId} is not waiting for resume");
        }

        $run->state->mergeData(['feedback' => $feedback]);
        $run->status = RunStatus::RUNNING;
        $run->updatedAt = time();
        $this->plugins->fireResume($run, $feedback);

        $pauseNode = $run->compiled->node($run->pauseNodeId);
        if ($pauseNode instanceof AbstractNode) {
            $ctx = new RunContext($run->runId, $run->compiled);
            $pauseNode->resume($ctx, $run->state, $feedback);
        }

        $next = $this->scheduler->resolveNextNode($run->compiled, $run->pauseNodeId, $run->state);
        $run->pauseNodeId = null;
        $this->runStore->save($run);

        if ($next === null) {
            $run->status = RunStatus::COMPLETED;
            $this->runStore->save($run);
            $this->plugins->fireRunComplete($run);

            return;
        }

        try {
            $this->executeFromNode($run, $next);
        } catch (Throwable $e) {
            $this->handleRunFailure($run, $e);
            throw $e;
        }

        if ($run->status === RunStatus::RUNNING) {
            $run->status = RunStatus::COMPLETED;
            $run->currentNodeId = null;
            $run->updatedAt = time();
            $this->runStore->save($run);
        }

        $this->plugins->fireRunComplete($run);
    }

    /** 取消运行，状态设为 CANCELLED。 */
    public function cancel(string $runId): void
    {
        $run = $this->requireRun($runId);
        $run->status = RunStatus::CANCELLED;
        $run->updatedAt = time();
        $this->runStore->save($run);
    }

    /** 按 runId 获取运行实例，不存在抛 WorkflowException。 */
    public function getRun(string $runId): WorkflowRun
    {
        return $this->requireRun($runId);
    }

    /** 获取 Run 存储后端（可替换为 Redis 实现）。 */
    public function runStore(): RunStoreInterface
    {
        return $this->runStore;
    }

    /**
     * 列出 HITL 暂停任务（需 RunStore 实现 {@see PauseTaskQueryableInterface}）。
     *
     * @return list<array<string, mixed>>
     */
    public function listPauseTasks(?string $assignee = null): array
    {
        if (!$this->runStore instanceof PauseTaskQueryableInterface) {
            return [];
        }

        $tasks = [];
        foreach ($this->runStore->listWaiting($assignee) as $run) {
            $pauseOutput = $run->state->outputOf((string) $run->pauseNodeId) ?? [];
            $tasks[] = [
                'runId' => $run->runId,
                'workflowId' => $run->compiled->workflowId(),
                'pauseNodeId' => $run->pauseNodeId,
                'assignee' => is_array($pauseOutput) ? ($pauseOutput['assignee'] ?? null) : null,
                'title' => is_array($pauseOutput) ? ($pauseOutput['title'] ?? null) : null,
                'payload' => is_array($pauseOutput) ? ($pauseOutput['payload'] ?? []) : [],
                'updatedAt' => $run->updatedAt,
            ];
        }

        return $tasks;
    }

    /**
     * 从指定节点沿 DAG 顺序执行，直到无下一跳、WAITING 或 FAILED。
     * 每个节点执行后保存 Run 快照（便于观测与后续 Redis 持久化）。
     */
    private function executeFromNode(WorkflowRun $run, string $nodeId): void
    {
        $current = $nodeId;

        while ($current !== null) {
            $node = $run->compiled->node($current);
            if ($node === null) {
                throw new WorkflowException("Node {$current} not found in compiled workflow");
            }

            $run->currentNodeId = $current;
            $run->updatedAt = time();
            $this->runStore->save($run);

            $result = $this->executeNode($run, $node);

            if ($result->status === NodeStatus::WAITING) {
                $this->persistNodeOutput($run->state, $current, $result);
                $run->status = RunStatus::WAITING;
                $run->pauseNodeId = $current;
                $run->updatedAt = time();
                $this->runStore->save($run);
                $this->plugins->firePause($run, $node);

                return;
            }

            if ($result->status === NodeStatus::FAILED) {
                $this->handleNodeFailure($run, $current, $result);

                return;
            }

            if ($result->status !== NodeStatus::SUCCESS) {
                throw new WorkflowException("Unexpected node status {$result->status->value}");
            }

            $this->persistNodeOutput($run->state, $current, $result);
            $this->publishResultEvents($run, $current, $result);
            // Saga：记录已成功节点，失败时 SagaCoordinator 逆序 compensate
            $run->executedNodeIds[] = $current;

            $current = $this->scheduler->resolveNextNode($run->compiled, $current, $run->state);

            if ($current !== null) {
                $run->lastRoutedEdge = $current;
                $this->events->publish('edge.route', [
                    'runId' => $run->runId,
                    'from' => $run->currentNodeId,
                    'selectedTarget' => $current,
                ]);
            }
        }
    }

    /**
     * 执行单个节点：TimeoutGuard + RetryExecutor + Plugin 钩子。
     */
    private function executeNode(WorkflowRun $run, NodeInterface $node): NodeExecutionResult
    {
        $timeout = $this->resolveNodeTimeout($node);

        return $this->retryExecutor->execute(
            $node,
            new RunContext($run->runId, $run->compiled, 1),
            $run->state,
            function (NodeInterface $node, RunContext $ctx, WorkflowState $state) use ($timeout): NodeExecutionResult {
                return TimeoutGuard::run(
                    fn (): NodeExecutionResult => $this->executeNodeOnce($node, $ctx, $state),
                    $timeout,
                );
            },
            $this->defaultRetryPolicy,
        );
    }

    /** 单次节点执行（不含重试循环）。 */
    private function executeNodeOnce(NodeInterface $node, RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $this->plugins->fireNodeBefore($ctx, $node, $state);
        $startedAt = microtime(true);

        try {
            if ($node instanceof AbstractNode) {
                $result = $node->run($ctx, $state);
            } else {
                $node->beforeExecute($ctx, $state);
                $result = $node->execute($ctx, $state);
                if ($result->status === NodeStatus::SUCCESS) {
                    $node->afterExecute($ctx, $state, $result);
                }
            }
        } catch (Throwable $e) {
            $result = NodeExecutionResult::failed($e);
        }

        $result->metrics['latencyMs'] = (int) ((microtime(true) - $startedAt) * 1000);

        if ($result->status === NodeStatus::FAILED) {
            $this->plugins->fireNodeFail($ctx, $node, $state, $result);
        } else {
            $this->plugins->fireNodeAfter($ctx, $node, $state, $result);
        }

        return $result;
    }

    private function resolveNodeTimeout(NodeInterface $node): float
    {
        if ($node instanceof \Swoolefy\Support\AI\Node\AINode) {
            $timeout = $node->configuredTimeoutSeconds();
            if ($timeout > 0) {
                return (float) $timeout;
            }
        }

        return $this->defaultNodeTimeoutSeconds;
    }

    /**
     * 持久化节点输出：写入 nodeOutputs[nodeId]，并将关联数组键合并进 data。
     * 使 AINode 输出可被条件边表达式读取，如 data['decision']['approved']。
     */
    private function persistNodeOutput(WorkflowState $state, string $nodeId, NodeExecutionResult $result): void
    {
        $state->setNodeOutput($nodeId, $result->output);

        if (is_array($result->output)) {
            foreach ($result->output as $key => $value) {
                if (is_string($key)) {
                    $state->set($key, $value);
                }
            }
        }
    }

    /** 将 NodeExecutionResult.events 发布到对外 EventBus（SSE/WS）。 */
    private function publishResultEvents(WorkflowRun $run, string $nodeId, NodeExecutionResult $result): void
    {
        foreach ($result->events as $eventName => $payload) {
            $this->events->publish((string) $eventName, is_array($payload) ? $payload : ['value' => $payload]);
        }
    }

    /** 内部：加载 Run，不存在则抛异常。 */
    private function requireRun(string $runId): WorkflowRun
    {
        $run = $this->runStore->find($runId);
        if ($run === null) {
            throw new WorkflowException("Run {$runId} not found");
        }

        return $run;
    }

    /** 生成唯一 runId。 */
    private function generateRunId(): string
    {
        return 'run_' . bin2hex(random_bytes(8));
    }

    /**
     * 节点 FAILED 处理：写 error，可选触发 Saga 补偿后抛 WorkflowException。
     *
     * Saga 路径：runCompensation → COMPENSATED；非 Saga：FAILED。
     */
    private function handleNodeFailure(WorkflowRun $run, string $nodeId, NodeExecutionResult $result): void
    {
        $message = $result->error?->getMessage() ?? 'Node failed';
        $run->error = "Node {$nodeId} failed: {$message}";
        $run->updatedAt = time();

        if ($this->isSagaEnabled($run)) {
            $this->runCompensation($run);
        } else {
            $run->status = RunStatus::FAILED;
            $this->runStore->save($run);
        }

        throw new WorkflowException($run->error);
    }

    /**
     * start/resume 外层 catch：更新 Run 状态并 fireRunComplete 释放 Plugin 槽位。
     *
     * 注意：COMPENSATED / COMPENSATING / WAITING 不被覆盖为 FAILED。
     */
    private function handleRunFailure(WorkflowRun $run, Throwable $e): void
    {
        if (!in_array($run->status, [RunStatus::COMPENSATED, RunStatus::COMPENSATING, RunStatus::WAITING], true)) {
            $run->status = RunStatus::FAILED;
        }
        if ($run->error === null) {
            $run->error = $e->getMessage();
        }
        $run->updatedAt = time();
        $this->runStore->save($run);
        $this->plugins->fireRunComplete($run);
    }

    /**
     * 执行 Saga 逆序补偿，结果写入 state.compensatedNodes。
     *
     * 成功 → RunStatus::COMPENSATED；补偿异常 → FAILED 并追加 error。
     */
    private function runCompensation(WorkflowRun $run): void
    {
        $run->status = RunStatus::COMPENSATING;
        $this->runStore->save($run);

        try {
            $sagaResult = $this->sagaCoordinator->compensate($run, $run->executedNodeIds);
            $run->state->set('compensatedNodes', $sagaResult->compensatedNodeIds);
            $run->status = RunStatus::COMPENSATED;
        } catch (Throwable $e) {
            $run->status = RunStatus::FAILED;
            $run->error = ($run->error ?? '') . ' | compensation: ' . $e->getMessage();
        }

        $run->updatedAt = time();
        $this->runStore->save($run);
    }

    /** 是否启用 Saga：Definition.metadata.saga = true 且已有成功节点。 */
    private function isSagaEnabled(WorkflowRun $run): bool
    {
        $meta = $run->compiled->metadata();

        return filter_var($meta['saga'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $run->executedNodeIds !== [];
    }
}
