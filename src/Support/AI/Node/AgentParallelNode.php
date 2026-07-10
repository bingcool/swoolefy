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

/**
 * 多 Agent 并行执行节点。
 *
 * ---------------------------------------------------------------------------
 * 定位
 * ---------------------------------------------------------------------------
 *
 * 在单个 Workflow 节点内扇出多个 Agent 任务，由 {@see AgentScheduler} 在 Swoole
 * 协程中并发执行；由 {@see AgentRouterInterface} 决定本轮实际跑哪些 agentId。
 *
 * 典型声明（Definition DSL）：
 *
 *   $definition->addAgentParallel('parallel_prepare', [
 *       'scheduler' => $scheduler,
 *       'router'    => new StaticRouter(['weather', 'route', 'bike']),
 *       'agents'    => [
 *           'weather' => fn (RouterContext $ctx, NeuronFactory $f) => [...],
 *           'route'   => ...,
 *           'bike'    => ...,
 *       ],
 *       'timeout'   => 120,      // 可选，秒
 *       'failFast'  => false,    // 可选
 *   ]);
 *
 * ---------------------------------------------------------------------------
 * 执行流程
 * ---------------------------------------------------------------------------
 *
 *   1. 解析超时：节点 timeoutSeconds > 0 用节点值，否则用 RunContext.nodeTimeoutSeconds
 *   2. 组装 RouterContext（runId / state / availableAgents / timeout）
 *   3. scheduler->runParallel(ctx, tasks, router, failFast)
 *        - router->route(ctx) 得到选中的 agentId 列表
 *        - 仅对选中 id 调用 tasks[id]
 *        - 返回 array<agentId, mixed>
 *   4. 扫描返回值中带 'error' 键的项 → failedAgents
 *   5. 有失败 → NodeExecutionResult::failed；否则 success
 *
 * ---------------------------------------------------------------------------
 * 失败策略
 * ---------------------------------------------------------------------------
 *
 *   - failFast=true：Scheduler 内首个 Agent 抛异常即中断并向上抛（节点 FAILED）
 *   - failFast=false（默认）：尽量跑完；若某 Agent 返回 ['error' => ...] 则本节点 FAILED，
 *     但仍把已收集的 agentOutputs / failedAgents 写入 payload（便于排查）
 *
 * ---------------------------------------------------------------------------
 * 输出写入 state（经 NodeExecutionResult data merge）
 * ---------------------------------------------------------------------------
 *
 *   - selectedAgents  list<string>           实际执行的 agentId
 *   - agentOutputs    array<string, mixed>  各 Agent 返回值（亦写入 state.agentOutputs 属性，
 *                                           见 AgentScheduler / Engine 约定）
 *   - failedAgents    list<string>           仅失败时存在
 *
 * @see AgentScheduler
 * @see WorkflowDefinition::addAgentParallel()
 * @see docs/SwoolefyAI.md §4.3
 */
final class AgentParallelNode extends AbstractNode implements ConfigurableTimeoutNodeInterface
{
    /**
     * @param array<string, callable(RouterContext, \Swoolefy\Support\Neuron\NeuronFactory): mixed> $tasks
     *        agentId => 回调；回调返回值原样进入 outputs[agentId]
     * @param int  $timeoutSeconds 节点超时秒数；0 = 不覆盖，使用引擎 RunContext.nodeTimeoutSeconds
     * @param bool $failFast       true 时首个 Agent 异常立即失败；false 时尽量收集全部结果
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

    /**
     * 节点级超时（秒），供 TimeoutGuard 使用。
     *
     * {@inheritdoc}
     */
    public function configuredTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * 路由 → 并行执行 → 汇总成功 / 失败。
     *
     * {@inheritdoc}
     *
     * @throws WorkflowException 当 failFast 路径下 Scheduler 抛错，或收集到 error 载荷时（failed 结果）
     */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        // 节点显式 timeout 优先；否则继承引擎为本节点解析的默认超时（可能来自全局配置）。
        $timeout = $this->timeoutSeconds > 0
            ? (float) $this->timeoutSeconds
            : $ctx->nodeTimeoutSeconds;

        // RouterContext：Router 只读 availableAgents + state 做选择；Scheduler 用同一 ctx 跑任务。
        $routerCtx = new RouterContext(
            runId: $ctx->runId,
            state: $state,
            // 候选全集 = 注册的 task 键；Router 可返回子集（如 RuleRouter 按 state 裁剪）。
            availableAgents: array_keys($this->tasks),
            timeoutSeconds: $timeout,
        );

        // 协程并行；返回 map 的键 = 实际选中并执行的 agentId。
        $outputs = $this->scheduler->runParallel(
            $routerCtx,
            $this->tasks,
            $this->router,
            $this->failFast,
        );

        // failFast=false 时，失败 Agent 常以 ['error' => '...'] 形式出现在 outputs 里，而非抛异常。
        $failedAgents = self::collectFailedAgents($outputs);
        $payload = [
            'selectedAgents' => array_keys($outputs),
            'agentOutputs' => $outputs,
        ];

        if ($failedAgents !== []) {
            $payload['failedAgents'] = $failedAgents;

            // failed() 仍携带 payload，便于 status API / 日志看到部分成功结果。
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
     * 从 Scheduler 输出中收集「软失败」Agent。
     *
     * 约定：返回值为 array 且含键 'error' 即视为该 Agent 失败
     *（与 AgentScheduler 在非 failFast 下捕获异常后的包装格式对齐）。
     *
     * @param array<string, mixed> $outputs
     *
     * @return list<string> 失败的 agentId 列表
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
