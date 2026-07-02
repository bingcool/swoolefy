<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 多 Agent 并行节点 —— 委托 {@see AgentScheduler} 协程并发执行。
 *
 * 结果写入 state.agentOutputs[agentId]，并合并 selectedAgents 到 output。
 */
final class AgentParallelNode extends AbstractNode
{
    /**
     * @param array<string, callable(RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed> $tasks
     */
    public function __construct(
        string $nodeId,
        private readonly AgentScheduler $scheduler,
        private readonly AgentRouterInterface $router,
        private readonly array $tasks,
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $routerCtx = new RouterContext(
            runId: $ctx->runId,
            state: $state,
            availableAgents: array_keys($this->tasks),
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
