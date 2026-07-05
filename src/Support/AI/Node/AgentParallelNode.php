<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 多 Agent 并行执行节点。
 *
 * 委托 {@see AgentScheduler} 在协程中并发运行多个 Agent 任务，
 * 由 {@see AgentRouterInterface} 决定实际参与执行的 Agent 子集。
 *
 * 超时：
 *   - 构造参数 timeoutSeconds > 0 → 节点级超时
 *   - timeoutSeconds = 0 → 使用 WorkflowEngine.defaultNodeTimeoutSeconds
 *   - 未配置引擎默认时，RouterContext 内部 fallback 为 60s
 *
 * 输出写入 state：
 *   - selectedAgents — 实际执行的 Agent 名列表
 *   - agentOutputs   — 各 Agent 返回内容（合并进 state.data）
 */
final class AgentParallelNode extends AbstractNode implements ConfigurableTimeoutNodeInterface
{
    /**
     * @param array<string, callable(RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed> $tasks
     *                                                                                                    Agent 名 → 执行闭包
     * @param int $timeoutSeconds 节点超时；0 = 使用引擎 defaultNodeTimeoutSeconds
     */
    public function __construct(
        string $nodeId,
        private readonly AgentScheduler $scheduler,
        private readonly AgentRouterInterface $router,
        private readonly array $tasks,
        private readonly int $timeoutSeconds = 0,
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} 返回构造时指定的秒数；0 表示交给引擎全局默认。 */
    public function configuredTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        // RouterContext 超时：节点级优先，否则 60s 兜底（引擎层另有 TimeoutGuard）
        $timeout = $this->timeoutSeconds > 0
            ? (float) $this->timeoutSeconds
            : 60.0;

        $routerCtx = new RouterContext(
            runId: $ctx->runId,
            state: $state,
            availableAgents: array_keys($this->tasks),
            timeoutSeconds: $timeout,
        );

        $outputs = $this->scheduler->runParallel($routerCtx, $this->tasks, $this->router);

        return NodeExecutionResult::success([
            'selectedAgents' => array_keys($outputs),
            'agentOutputs' => $outputs,
        ], metrics: [
            'nodeType' => 'agent_parallel',
            'agentCount' => count($outputs),
        ]);
    }
}
