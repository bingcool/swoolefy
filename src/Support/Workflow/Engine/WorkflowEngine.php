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
        // 生成全局唯一 runId，格式 run_YYYYMMDD_{16位hex}
        $runId = WorkflowRunTime::generateRunId();
        // 按编译期 schema 校验并规范化初始输入，写入 WorkflowState.data
        $state = WorkflowState::fromInput($input, $compiled->schemas());
        $now = WorkflowRunTime::now();

        // 新建 Run 快照，初始状态 RUNNING（尚未执行任何节点）
        $run = new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: RunStatus::RUNNING,
            state: $state,
            createdAt: $now,
            updatedAt: $now,
        );

        // 通知插件 Run 开始（Metrics / Tracing 等可在此记录起点）
        $this->plugins->fireRunStart($run, $input);
        // 首次落库，便于外部通过 runId 观测到「已创建」状态
        $this->runStore->save($run);

        try {
            // Phase 1 约定单入口：编译器保证 entryNodes 至少有一个
            $entry = $compiled->entryNodes()[0];
            $this->executeFromNode($run, $entry);
        } catch (Throwable $e) {
            // 节点异常 / Saga 补偿失败等：统一标记 FAILED 并释放 Plugin 槽位
            $this->handleRunFailure($run, $e);
            throw $e;
        }

        // executeFromNode 正常跑完 DAG 后 status 仍为 RUNNING，此处转为终态 COMPLETED
        // 若中途进入 WAITING（HITL）或 FAILED / CANCELLED，则跳过此分支
        if ($run->status === RunStatus::RUNNING) {
            $run->status = RunStatus::COMPLETED;
            $run->currentNodeId = null;
            $run->updatedAt = WorkflowRunTime::now();
            $this->runStore->save($run);
        }

        // WAITING 表示 Run 尚未结束，需等待 resume；此时不触发 run.complete，与 resume() 行为对齐
        if ($run->status !== RunStatus::WAITING) {
            $this->plugins->fireRunComplete($run);
        }

        return $runId;
    }

    /**
     * 恢复处于 WAITING 状态的 Run（HITL 人工审批后继续）。
     *
     * 流程：
     *   1. 校验 Run 处于 WAITING 且 pauseNodeId 非空
     *   2. 合并 feedback 到 state.data
     *   3. CAS：saveIfStatus(WAITING) — 防并发 double-resume；CAS 前清空 pauseNodeId 并落库
     *   4. 调用 PauseNode::resume + 重新求值条件边
     *   5. 从下一节点继续 executeFromNode
     *
     * @param array<string, mixed> $feedback 审批结果，如 ['approved' => true, 'reason' => 'ok']
     *
     * @throws WorkflowException Run 非 WAITING 或 CAS 失败
     */
    public function resume(string $runId, array $feedback): void
    {
        $run = $this->requireRun($runId);

        // 内存态校验：只有 WAITING 且记录了暂停节点才允许 resume
        if ($run->status !== RunStatus::WAITING || $run->pauseNodeId === null) {
            throw new WorkflowException("Run {$runId} is not waiting for resume");
        }

        // 先保存 pauseNodeId 到局部变量：后续清空 run.pauseNodeId 后仍需要用它做路由
        $pauseNodeId = $run->pauseNodeId;
        // 审批结果写入 state，条件边可读取 data.feedback.approved 等字段
        $run->state->mergeData(['feedback' => $feedback]);
        $run->status = RunStatus::RUNNING;
        // CAS 前清空 pauseNodeId：若 CAS 成功后进程崩溃，持久化层不会仍显示「运行中且暂停中」
        $run->pauseNodeId = null;
        $run->updatedAt = WorkflowRunTime::now();

        // CAS：仅当 DB/Redis 中 status 仍为 WAITING 时才写入 RUNNING
        // 与 cancel(WAITING) 互斥，防止两个 Worker 同时 resume 同一 Run
        if (!$this->runStore->saveIfStatus($run, RunStatus::WAITING)) {
            throw new WorkflowException(
                "Run {$runId} is not waiting for resume (already resumed or cancelled?)",
            );
        }

        $this->plugins->fireResume($run, $feedback);

        // 调用 PauseNode 的 resume 钩子，允许节点根据 feedback 修改 state
        $pauseNode = $run->compiled->node($pauseNodeId);
        if ($pauseNode instanceof AbstractNode) {
            $ctx = new RunContext($run->runId, $run->compiled);
            $pauseNode->resume($ctx, $run->state, $feedback);
        }

        // 基于更新后的 state 重新求值暂停节点的出边（条件边 / 默认边）
        $next = $this->scheduler->resolveNextNode($run->compiled, $pauseNodeId, $run->state);
        // 持久化 resume 后的 state（含 feedback、PauseNode 副作用）
        $this->runStore->save($run);

        // 暂停节点之后无后继：直接完成（例如审批拒绝且无后续分支）
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

        // 与 start() 相同：正常跑完 DAG 后 RUNNING → COMPLETED
        if ($run->status === RunStatus::RUNNING) {
            $run->status = RunStatus::COMPLETED;
            $run->currentNodeId = null;
            $run->updatedAt = WorkflowRunTime::now();
            $this->runStore->save($run);
        }

        // 与 start() 对齐：resume 后再次进入 WAITING 时不触发 run.complete
        if ($run->status !== RunStatus::WAITING) {
            $this->plugins->fireRunComplete($run);
        }
    }

    /**
     * 取消运行。
     *
     * WAITING：CAS saveIfStatus(WAITING) → CANCELLED，与 resume 竞态互斥。
     * RUNNING：协作式取消 —— 写入 _cancelRequested 标志并保持 RUNNING，
     *          executeFromNode 在节点间隙检测后转为 CANCELLED。
     *
     * @throws WorkflowException 终态不可取消，或 CAS 失败（并发 resume/cancel）
     */
    public function cancel(string $runId): void
    {
        $run = $this->requireRun($runId);

        // 终态 Run 不可再取消，避免覆盖历史结果
        if (in_array($run->status, [
            RunStatus::COMPLETED,
            RunStatus::FAILED,
            RunStatus::CANCELLED,
            RunStatus::COMPENSATED,
            RunStatus::COMPENSATING,
        ], true)) {
            throw new WorkflowException(
                "Run {$runId} cannot be cancelled in status {$run->status->value}",
            );
        }

        $run->updatedAt = WorkflowRunTime::now();

        if ($run->status === RunStatus::WAITING) {
            // HITL 暂停中：CAS 原子改为 CANCELLED，与 resume 的 CAS(WAITING→RUNNING) 互斥
            $run->status = RunStatus::CANCELLED;
            if (!$this->runStore->saveIfStatus($run, RunStatus::WAITING)) {
                throw new WorkflowException(
                    "Run {$runId} cancel failed (status changed concurrently)",
                );
            }

            // WAITING Run 在 start/resume 时不会触发 run.complete；取消后进入终态，必须释放插件资源。
            $this->plugins->fireRunComplete($run);

            return;
        }

        if ($run->status === RunStatus::RUNNING) {
            // 正在执行节点：无法立即打断 LLM/MCP 调用，写入协作式取消标志
            // executeFromNode 在每个节点开始前从 RunStore 重新读取并检测
            $run->state->set('_cancelRequested', true);
            // CAS 确保 Run 仍处于 RUNNING，防止与 resume 或其他 cancel 竞态
            if (!$this->runStore->saveIfStatus($run, RunStatus::RUNNING)) {
                throw new WorkflowException(
                    "Run {$runId} cancel failed (status changed concurrently)",
                );
            }

            return;
        }

        // 兜底：其他非终态直接标记 CANCELLED
        $run->status = RunStatus::CANCELLED;
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

    public function getDefaultNodeTimeoutSeconds(): float
    {
        return $this->defaultNodeTimeoutSeconds;
    }

    /**
     * 列出 HITL 暂停任务（需 RunStore 实现 {@see PauseTaskQueryableInterface}）。
     *
     * @return list<array<string, mixed>>
     */
    public function listPauseTasks(?string $assignee = null): array
    {
        // 仅支持可查询 WAITING 列表的存储后端（DbRunStore / RedisRunStore / InMemoryRunStore）
        if (!$this->runStore instanceof PauseTaskQueryableInterface) {
            return [];
        }

        $tasks = [];
        foreach ($this->runStore->listWaiting($assignee) as $run) {
            // 任务详情来自 PauseNode 执行时写入的 nodeOutputs[pauseNodeId]
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

        // 线性遍历 DAG：每次 SUCCESS 后 resolveNextNode 决定下一跳
        while ($current !== null) {
            // 节点间隙检测取消请求（支持跨 Worker 协作式 cancel）
            if ($this->applyCancellationIfRequested($run)) {
                return;
            }

            $node = $run->compiled->node($current);
            if ($node === null) {
                throw new WorkflowException("Node {$current} not found in compiled workflow");
            }

            // 节点执行前先落库 currentNodeId，便于外部观测「正在执行哪个节点」
            $run->currentNodeId = $current;
            $run->updatedAt = WorkflowRunTime::now();
            $this->runStore->save($run);

            $result = $this->executeNode($run, $node);

            if ($result->status === NodeStatus::WAITING) {
                // HITL / PauseNode：持久化输出并进入 WAITING，等待 resume
                $this->persistNodeOutput($run->state, $current, $result);
                $run->status = RunStatus::WAITING;
                $run->pauseNodeId = $current;
                $run->updatedAt = WorkflowRunTime::now();
                $this->runStore->save($run);
                $this->plugins->firePause($run, $node);

                return;
            }

            if ($result->status === NodeStatus::FAILED) {
                // 节点失败：可选 Saga 补偿，然后抛异常中断 executeFromNode
                $this->handleNodeFailure($run, $current, $result);

                return;
            }

            if ($result->status !== NodeStatus::SUCCESS) {
                throw new WorkflowException("Unexpected node status {$result->status->value}");
            }

            // SUCCESS：合并输出到 state，发布 SSE 事件，记录 Saga 已成功节点
            $this->persistNodeOutput($run->state, $current, $result);
            $this->publishResultEvents($run, $current, $result);
            $run->executedNodeIds[] = $current;

            // 根据条件边 / 默认边解析下一节点；null 表示 DAG 结束
            $current = $this->scheduler->resolveNextNode($run->compiled, $current, $run->state);

            if ($current !== null) {
                $run->lastRoutedEdge = $current;
                // 对外广播路由事件，前端可展示「从 A 到 B」
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
        // 解析本节点超时：节点级配置 > 引擎 defaultNodeTimeoutSeconds
        $timeout = $this->resolveNodeTimeout($node);

        return $this->retryExecutor->execute(
            $node,
            // nodeTimeoutSeconds 传入 RunContext，供 AgentParallelNode 等内部调度与引擎超时对齐
            new RunContext($run->runId, $run->compiled, 1, [], $timeout),
            $run->state,
            function (NodeInterface $node, RunContext $ctx, WorkflowState $state) use ($timeout): NodeExecutionResult {
                // TimeoutGuard 在 Swoole 协程内用 Channel 超时；CLI 非协程环境不强制超时
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
                // 推荐路径：AbstractNode::run() 内部统一 beforeExecute → execute → afterExecute
                $result = $node->run($ctx, $state);
            } else {
                // 兼容直接实现 NodeInterface 的自定义节点
                $node->beforeExecute($ctx, $state);
                $result = $node->execute($ctx, $state);
                if ($result->status === NodeStatus::SUCCESS) {
                    $node->afterExecute($ctx, $state, $result);
                }
            }
        } catch (Throwable $e) {
            // 未捕获异常统一转为 FAILED 结果，由上层 handleNodeFailure 处理
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

    /**
     * 解析节点执行超时秒数。
     *
     * 优先级：ConfigurableTimeoutNodeInterface.configuredTimeoutSeconds() > 0
     *        → 引擎 defaultNodeTimeoutSeconds（来自 workflow.php）
     */
    private function resolveNodeTimeout(NodeInterface $node): float
    {
        if ($node instanceof \Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface) {
            $timeout = $node->configuredTimeoutSeconds();
            // 返回 0 表示节点未单独配置，回退引擎全局默认
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
        // 完整输出保留在 nodeOutputs，供 listPauseTasks / 调试回溯
        $state->setNodeOutput($nodeId, $result->output);

        // 将 output 的顶层 string key 扁平合并到 data，方便条件边直接引用
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

    /**
     * 节点 FAILED 处理：写 error，可选触发 Saga 补偿后抛 WorkflowException。
     *
     * Saga 路径：runCompensation → COMPENSATED；非 Saga：FAILED。
     */
    private function handleNodeFailure(WorkflowRun $run, string $nodeId, NodeExecutionResult $result): void
    {
        $message = $result->error?->getMessage() ?? 'Node failed';
        $run->error = "Node {$nodeId} failed: {$message}";
        $run->updatedAt = WorkflowRunTime::now();

        if ($this->isSagaEnabled($run)) {
            // Saga 模式：逆序 compensate 已成功节点，最终状态 COMPENSATED 或补偿失败 FAILED
            $this->runCompensation($run);
        } else {
            $run->status = RunStatus::FAILED;
            $this->runStore->save($run);
        }

        // 抛异常中断 executeFromNode，由 start/resume 外层 catch 进入 handleRunFailure
        throw new WorkflowException($run->error);
    }

    /**
     * start/resume 外层 catch：更新 Run 状态并 fireRunComplete 释放 Plugin 槽位。
     *
     * 注意：COMPENSATED / COMPENSATING / WAITING 不被覆盖为 FAILED。
     */
    private function handleRunFailure(WorkflowRun $run, Throwable $e): void
    {
        // Saga 补偿完成 / 进行中、HITL 暂停、已取消：保留原 status，不强制改 FAILED
        if (!in_array($run->status, [RunStatus::COMPENSATED, RunStatus::COMPENSATING, RunStatus::WAITING, RunStatus::CANCELLED], true)) {
            $run->status = RunStatus::FAILED;
        }
        if ($run->error === null) {
            $run->error = $e->getMessage();
        }
        $run->updatedAt = WorkflowRunTime::now();
        $this->runStore->save($run);
        // 失败也触发 run.complete，确保 Plugin 释放资源（与成功路径对称）
        $this->plugins->fireRunComplete($run);
    }

    /**
     * 执行 Saga 逆序补偿，结果写入 state.compensatedNodes。
     *
     * 成功 → RunStatus::COMPENSATED；补偿异常 → FAILED 并追加 error。
     */
    private function runCompensation(WorkflowRun $run): void
    {
        // 先标记 COMPENSATING 并落库，便于观测补偿进行中
        $run->status = RunStatus::COMPENSATING;
        $this->runStore->save($run);

        try {
            // 按 executedNodeIds 逆序调用各节点的 compensate 钩子
            $sagaResult = $this->sagaCoordinator->compensate($run, $run->executedNodeIds);
            $run->state->set('compensatedNodes', $sagaResult->compensatedNodeIds);
            $run->status = RunStatus::COMPENSATED;
        } catch (Throwable $e) {
            // 补偿本身失败：Run 终态 FAILED，error 追加补偿异常信息
            $run->status = RunStatus::FAILED;
            $run->error = ($run->error ?? '') . ' | compensation: ' . $e->getMessage();
        }

        $run->updatedAt = WorkflowRunTime::now();
        $this->runStore->save($run);
    }

    /** 是否启用 Saga：Definition.metadata.saga = true 且已有成功节点。 */
    private function isSagaEnabled(WorkflowRun $run): bool
    {
        $meta = $run->compiled->metadata();

        // 无已成功节点时无需补偿（例如入口节点即失败）
        return filter_var($meta['saga'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $run->executedNodeIds !== [];
    }

    /**
     * 检测持久化层的取消请求（协作式 cancel）。
     *
     * 从 RunStore 重新读取，支持跨 Worker cancel RUNNING 中的 Run。
     */
    private function applyCancellationIfRequested(WorkflowRun $run): bool
    {
        // 每次节点开始前从存储层读取最新快照，而非仅依赖内存态
        $fresh = $this->runStore->find($run->runId);
        if ($fresh === null) {
            return false;
        }

        // 其他 Worker 已将 WAITING Run CAS 为 CANCELLED
        if ($fresh->status === RunStatus::CANCELLED) {
            $run->status = RunStatus::CANCELLED;
            $run->updatedAt = WorkflowRunTime::now();
            $this->runStore->save($run);
            $this->plugins->fireRunComplete($run);

            return true;
        }

        // 本 Worker 或其他 Worker 写入了 _cancelRequested 协作式取消标志
        if ($fresh->state->get('_cancelRequested', false)) {
            $run->status = RunStatus::CANCELLED;
            $run->updatedAt = WorkflowRunTime::now();
            $this->runStore->save($run);
            $this->plugins->fireRunComplete($run);

            return true;
        }

        return false;
    }
}
