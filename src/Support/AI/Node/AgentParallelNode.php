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
 * 多 Agent 并行节点 —— 委托 {@see AgentScheduler} 协程并发执行。
 */
final class AgentParallelNode extends AbstractNode implements ConfigurableTimeoutNodeInterface
{
    /**
     * @param array<string, callable(RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed> $tasks
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

    /** {@inheritdoc} */
    public function configuredTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
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
