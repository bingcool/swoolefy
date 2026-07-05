<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Node;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowConfig;

/**
 * 多 Agent 并行执行节点。
 *
 * 委托 {@see AgentScheduler} 在协程中并发运行多个 Agent 任务，
 * 由 {@see AgentRouterInterface} 决定实际参与执行的 Agent 子集。
 *
 * 失败策略：
 *   - failFast=true：首个 Agent 异常立即抛出，节点 FAILED
 *   - failFast=false（默认）：收集全部结果；任一 Agent 返回含 error 键则节点 FAILED
 *
 * 输出写入 state：
 *   - selectedAgents — 实际执行的 Agent 名列表
 *   - agentOutputs   — 各 Agent 返回内容
 *   - failedAgents   — 失败 Agent 列表（有失败时）
 */
final class AgentParallelNode extends AbstractNode implements ConfigurableTimeoutNodeInterface
{
    /**
     * @param array<string, callable(RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed> $tasks
     * @param int  $timeoutSeconds 节点超时；0 = 使用引擎 defaultNodeTimeoutSeconds
     * @param bool $failFast       首个 Agent 异常是否立即失败
     */
    public function __construct(
        string $nodeId,
        private readonly AgentScheduler $scheduler,
        private readonly AgentRouterInterface $router,
        private readonly array $tasks,
        private readonly int $timeoutSeconds = 0,
        private readonly bool $failFast = false,
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
            : WorkflowConfig::load()->defaultNodeTimeoutSeconds();

        $routerCtx = new RouterContext(
            runId: $ctx->runId,
            state: $state,
            availableAgents: array_keys($this->tasks),
            timeoutSeconds: $timeout,
        );

        $outputs = $this->scheduler->runParallel(
            $routerCtx,
            $this->tasks,
            $this->router,
            $this->failFast,
        );

        $failedAgents = self::collectFailedAgents($outputs);
        $payload = [
            'selectedAgents' => array_keys($outputs),
            'agentOutputs' => $outputs,
        ];

        if ($failedAgents !== []) {
            $payload['failedAgents'] = $failedAgents;

            return NodeExecutionResult::failed(
                new WorkflowException(
                    'Agent parallel failures: ' . implode(', ', $failedAgents),
                ),
                $payload,
            );
        }

        return NodeExecutionResult::success($payload, metrics: [
            'nodeType' => 'agent_parallel',
            'agentCount' => count($outputs),
        ]);
    }

    /**
     * 检测 Scheduler 返回的 error 载荷。
     *
     * @param array<string, mixed> $outputs
     *
     * @return list<string>
     */
    private static function collectFailedAgents(array $outputs): array
    {
        $failed = [];
        foreach ($outputs as $agentId => $output) {
            if (is_array($output) && isset($output['error'])) {
                $failed[] = (string) $agentId;
            }
        }

        return $failed;
    }
}
