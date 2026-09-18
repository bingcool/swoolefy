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

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowRuntimeConflictException;
use Swoolefy\Support\Workflow\Exception\WorkflowRuntimeException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface;
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
 * 持久化：新建 Run 用 save()；之后 Runtime mutation 走 saveIfRevision，
 * 状态迁移走 saveIfStatusAndRevision。CAS 失败抛 WorkflowRuntimeConflictException，
 * 立即 Abort：不 Node FAILED、不 Saga、不 stale save。
 *
 * 横切能力（指标、追踪、重试元数据）走 {@see PluginManager}，不走 EventBus。
 * EventBus 仅用于对外 SSE/WebSocket 广播。
 *
 * @see docs/SwoolefyAI.md §6.1–§6.3
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
        $runId = WorkflowRunTime::generateRunId();
        $state = WorkflowState::fromInput($input, $compiled->schemas());
        $now = WorkflowRunTime::now();

        $run = new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: RunStatus::RUNNING,
            state: $state,
            createdAt: $now,
            updatedAt: $now,
        );

        $runStartAcquired = false;
        $runCompleteFired = false;
        try {
            $this->plugins->fireRunStart($run, $input);
            $runStartAcquired = true;
            // 首次落库 revision=0，是唯一允许的无条件 save()
            $this->runStore->save($run);

            try {
                $entry = $compiled->entryNodes()[0];
                $this->executeFromNode($run, $entry);
            } catch (WorkflowRuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->handleRunFailure($run, $e);
                $runCompleteFired = true;
                throw $e;
            }

            if ($run->status === RunStatus::RUNNING) {
                $run->status = RunStatus::COMPLETED;
                $run->currentNodeId = null;
                $run->updatedAt = WorkflowRunTime::now();
                $this->persistTransition($run, RunStatus::RUNNING);
            }

            if ($run->status !== RunStatus::WAITING) {
                $this->plugins->fireRunComplete($run);
                $runCompleteFired = true;
            }

            return $runId;
        } catch (Throwable $e) {
            // Runtime Abort 也要释放 RateLimit 槽位；WAITING 成功持久化走正常 return，不会进这里
            if ($runStartAcquired && !$runCompleteFired) {
                $this->plugins->fireRunComplete($run);
            }

            throw $e;
        }
    }

    /**
     * 恢复处于 WAITING 状态的 Run（HITL 人工审批后继续）。
     *
     * @param array<string, mixed> $feedback 审批结果，如 ['approved' => true, 'reason' => 'ok']
     *
     * @throws WorkflowException Run 非 WAITING 或 CAS 失败
     */
    public function resume(string $runId, array $feedback): void
    {
        $run = $this->requireRun($runId);

        if ($run->status !== RunStatus::WAITING || $run->pauseNodeId === null) {
            throw new WorkflowException("Run {$runId} is not waiting for resume");
        }

        $pauseNodeId = $run->pauseNodeId;
        $run->state->mergeData(['feedback' => $feedback]);
        $run->status = RunStatus::RUNNING;
        $run->pauseNodeId = null;
        $run->updatedAt = WorkflowRunTime::now();

        $this->persistTransition($run, RunStatus::WAITING);

        $this->plugins->fireResume($run, $feedback);

        $pauseNode = $run->compiled->node($pauseNodeId);
        try {
            if ($pauseNode instanceof AbstractNode) {
                $ctx = new RunContext($run->runId, $run->compiled);
                $pauseNode->resume($ctx, $run->state, $feedback);
            }
        } catch (Throwable $e) {
            // CAS RUNNING rev N → WAITING rev N+1；失败则 Conflict，禁止无条件 save()
            $run->status = RunStatus::WAITING;
            $run->pauseNodeId = $pauseNodeId;
            $run->updatedAt = WorkflowRunTime::now();
            $this->persistTransition($run, RunStatus::RUNNING);
            throw $e;
        }

        $next = $this->scheduler->resolveNextNode($run->compiled, $pauseNodeId, $run->state);
        $this->persistMutation($run);

        if ($next === null) {
            $run->status = RunStatus::COMPLETED;
            $this->persistTransition($run, RunStatus::RUNNING);
            $this->plugins->fireRunComplete($run);

            return;
        }

        try {
            $this->executeFromNode($run, $next);
        } catch (WorkflowRuntimeException $e) {
            if ($run->status !== RunStatus::WAITING) {
                $this->plugins->fireRunComplete($run);
            }
            throw $e;
        } catch (Throwable $e) {
            $this->handleRunFailure($run, $e);
            throw $e;
        }

        if ($run->status === RunStatus::RUNNING) {
            $run->status = RunStatus::COMPLETED;
            $run->currentNodeId = null;
            $run->updatedAt = WorkflowRunTime::now();
            $this->persistTransition($run, RunStatus::RUNNING);
        }

        if ($run->status !== RunStatus::WAITING) {
            $this->plugins->fireRunComplete($run);
        }
    }

    /**
     * 取消运行。
     *
     * WAITING：CAS status+revision → CANCELLED，与 resume 竞态互斥。
     * RUNNING：协作式取消，_cancelRequested 与 CAS 同一次写入。
     *
     * @throws WorkflowException 终态不可取消，或 CAS 失败（并发 resume/cancel）
     */
    public function cancel(string $runId): void
    {
        $run = $this->requireRun($runId);

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
            $from = $run->status;
            $run->status = RunStatus::CANCELLED;
            $this->persistTransition($run, $from);
            $this->plugins->fireRunComplete($run);

            return;
        }

        if ($run->status === RunStatus::RUNNING) {
            $run->state->set('_cancelRequested', true);
            $run->state->set('_runCompleteFired', true);
            $this->persistTransition($run, RunStatus::RUNNING);
            $this->plugins->fireRunComplete($run);

            return;
        }

        $from = $run->status;
        $run->status = RunStatus::CANCELLED;
        $this->persistTransition($run, $from);
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
     */
    private function executeFromNode(WorkflowRun $run, string $nodeId): void
    {
        $current = $nodeId;

        while ($current !== null) {
            if ($this->applyCancellationIfRequested($run)) {
                return;
            }

            $node = $run->compiled->node($current);
            if ($node === null) {
                throw new WorkflowException("Node {$current} not found in compiled workflow");
            }

            $run->currentNodeId = $current;
            $run->updatedAt = WorkflowRunTime::now();
            $this->persistMutation($run);

            $result = $this->executeNode($run, $node);

            if ($result->status === NodeStatus::WAITING) {
                $this->persistNodeOutput($run->state, $current, $result);
                $run->status = RunStatus::WAITING;
                $run->pauseNodeId = $current;
                $run->updatedAt = WorkflowRunTime::now();
                $this->persistTransition($run, RunStatus::RUNNING);
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
            new RunContext($run->runId, $run->compiled, 1, [], $timeout),
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

    /** 单次节点执行（不含重试循环）。Runtime 异常在转为 FAILED / fireNodeFail 之前抛出。 */
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
        } catch (WorkflowRuntimeException $e) {
            throw $e;
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

    /**
     * 解析节点执行超时秒数。
     *
     * 优先级：ConfigurableTimeoutNodeInterface.configuredTimeoutSeconds() > 0
     *        → 引擎 defaultNodeTimeoutSeconds（来自 workflow.php）
     */
    private function resolveNodeTimeout(NodeInterface $node): float
    {
        if ($node instanceof ConfigurableTimeoutNodeInterface) {
            $timeout = $node->configuredTimeoutSeconds();
            if ($timeout > 0) {
                return (float) $timeout;
            }
        }

        return $this->defaultNodeTimeoutSeconds;
    }

    /**
     * 持久化节点输出：写入 nodeOutputs[nodeId]，并将关联数组键合并进 data。
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

    /**
     * 同 status 下的 Runtime mutation：saveIfRevision(N)。
     */
    private function persistMutation(WorkflowRun $run): void
    {
        $expected = $run->revision;
        if (!$this->runStore->saveIfRevision($run, $expected)) {
            throw $this->conflict($run, $expected);
        }
    }

    /**
     * 状态迁移：saveIfStatusAndRevision(expectedStatus, N)，禁止拆成两次 CAS。
     */
    private function persistTransition(WorkflowRun $run, RunStatus $expectedStatus): void
    {
        $expected = $run->revision;
        if (!$this->runStore->saveIfStatusAndRevision($run, $expectedStatus, $expected)) {
            throw $this->conflict($run, $expected);
        }
    }

    private function conflict(WorkflowRun $run, int $expectedRevision): WorkflowRuntimeConflictException
    {
        return new WorkflowRuntimeConflictException(
            $run->runId,
            $run->compiled->workflowId(),
            $expectedRevision,
            $run->compiled->version(),
        );
    }

    /**
     * 节点 FAILED 处理：写 error，可选触发 Saga 补偿后抛 WorkflowException。
     *
     * Runtime 异常不得进入本方法（executeNodeOnce 已上抛）。
     */
    private function handleNodeFailure(WorkflowRun $run, string $nodeId, NodeExecutionResult $result): void
    {
        if ($result->error instanceof WorkflowRuntimeException) {
            throw $result->error;
        }

        $message = $result->error?->getMessage() ?? 'Node failed';
        $run->error = "Node {$nodeId} failed: {$message}";
        $run->updatedAt = WorkflowRunTime::now();

        if ($this->isSagaEnabled($run)) {
            $this->runCompensation($run);
        } else {
            $run->status = RunStatus::FAILED;
            $this->persistTransition($run, RunStatus::RUNNING);
        }

        throw new WorkflowException($run->error);
    }

    /**
     * start/resume 外层 catch：更新 Run 状态并 fireRunComplete 释放 Plugin 槽位。
     *
     * Runtime 不得进入；COMPENSATED / COMPENSATING / WAITING / CANCELLED 不被覆盖为 FAILED。
     */
    private function handleRunFailure(WorkflowRun $run, Throwable $e): void
    {
        if ($e instanceof WorkflowRuntimeException) {
            throw $e;
        }

        if (!in_array($run->status, [RunStatus::COMPENSATED, RunStatus::COMPENSATING, RunStatus::WAITING, RunStatus::CANCELLED], true)) {
            $run->status = RunStatus::FAILED;
        }
        if ($run->error === null) {
            $run->error = $e->getMessage();
        }
        $run->updatedAt = WorkflowRunTime::now();
        try {
            $this->persistMutation($run);
        } finally {
            $this->plugins->fireRunComplete($run);
        }
    }

    /**
     * 执行 Saga 逆序补偿。CAS 失败则 Runtime Abort，不要再 Saga 一遍。
     */
    private function runCompensation(WorkflowRun $run): void
    {
        $from = $run->status;
        $run->status = RunStatus::COMPENSATING;
        $this->persistTransition($run, $from);

        try {
            $sagaResult = $this->sagaCoordinator->compensate($run, $run->executedNodeIds);
            $run->state->set('compensatedNodes', $sagaResult->compensatedNodeIds);
            $run->status = RunStatus::COMPENSATED;
        } catch (WorkflowRuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            $run->status = RunStatus::FAILED;
            $run->error = ($run->error ?? '') . ' | compensation: ' . $e->getMessage();
        }

        $run->updatedAt = WorkflowRunTime::now();
        $this->persistTransition($run, RunStatus::COMPENSATING);
    }

    /** 是否启用 Saga：Definition.metadata.saga = true 且已有成功节点。 */
    private function isSagaEnabled(WorkflowRun $run): bool
    {
        $meta = $run->compiled->metadata();

        return filter_var($meta['saga'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && $run->executedNodeIds !== [];
    }

    /**
     * 检测持久化层的取消请求（协作式 cancel）。
     *
     * 已是 CANCELLED：只对齐内存，禁止 save 旧对象。
     * _cancelRequested：基于 fresh.revision 写成 CANCELLED。
     */
    private function applyCancellationIfRequested(WorkflowRun $run): bool
    {
        $fresh = $this->runStore->find($run->runId);
        if ($fresh === null) {
            return false;
        }

        if ($fresh->status === RunStatus::CANCELLED) {
            $run->status = RunStatus::CANCELLED;
            $run->revision = $fresh->revision;
            $run->updatedAt = WorkflowRunTime::now();
            $this->plugins->fireRunComplete($run);

            return true;
        }

        if ($fresh->state->get('_cancelRequested', false)) {
            $completeAlreadyFired = (bool) $fresh->state->get('_runCompleteFired', false);
            $expectedRevision = $fresh->revision;
            $fresh->status = RunStatus::CANCELLED;
            $fresh->updatedAt = WorkflowRunTime::now();
            if (!$this->runStore->saveIfStatusAndRevision($fresh, RunStatus::RUNNING, $expectedRevision)) {
                throw $this->conflict($fresh, $expectedRevision);
            }
            $run->status = RunStatus::CANCELLED;
            $run->revision = $fresh->revision;
            $run->updatedAt = $fresh->updatedAt;
            if (!$completeAlreadyFired) {
                $this->plugins->fireRunComplete($run);
            }

            return true;
        }

        return false;
    }
}
