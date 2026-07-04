<?php

declare(strict_types=1);

namespace Test\Module\Research\Workflow;

use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Test\Module\Research\Agent\CodingResearchAgent;
use Test\Module\Research\Agent\FinanceResearchAgent;

/**
 * 多 Agent 并行研究工作流（workflowId: multi_agent_research，version: 1.0.0）。
 *
 * 演示 Phase 2：同一研究主题由多个专业 Agent 并行分析，再汇总结果。
 *
 * DAG（固定边，无条件分支）：
 *
 *   parallel_research（AgentParallelNode）
 *        │  StaticRouter 固定选中 coding + finance
 *        │  两个 Agent 并行执行，结果写入 state.agentOutputs[agentId]
 *        ▼
 *     summary（ClosureNode）
 *        │  读取 agentOutputs，写入 data.summary{agentCount, topics}
 *        ▼
 *     （终态 completed）
 *
 * 各阶段写入的 state：
 *   - parallel_research: agentOutputs['coding']、agentOutputs['finance']（各含 topic / content）
 *   - summary:           data['summary'] = { agentCount, topics: ['coding','finance'] }
 *
 * 用法示例：
 *   // 真实 Agent（无 OPENAI_API_KEY 时各 Agent 内置 Fake 回退）
 *   $def = MultiAgentResearchWorkflow::definition($scheduler);
 *
 *   // HTTP 演示：跳过 LLM，返回确定性 mock 文案
 *   $def = MultiAgentResearchWorkflow::definition($scheduler, useMockAgents: true);
 *
 * @see Test\Module\Research\README.md
 * @see Swoolefy\Support\AI\Node\AgentParallelNode
 */
final class MultiAgentResearchWorkflow
{
    /**
     * 构建纯工作流定义（仅描述 DAG，不启动引擎）。
     *
     * @param AgentScheduler $scheduler     多 Agent 调度器（内部持有 NeuronFactory）
     * @param bool           $useMockAgents 为 true 时不调用 LLM，直接返回 mock 内容（演示 / 无网环境）
     */
    public static function definition(
        AgentScheduler $scheduler,
        bool $useMockAgents = false,
    ): WorkflowDefinition {
        return WorkflowDefinition::create('multi_agent_research', '1.0.0')
            // 可选元数据，供注册中心 / 运维看板使用。
            ->metadata([
                'owner' => 'research-team',
                'description' => 'Parallel coding + finance research agents, then summary',
            ])
            // AgentParallelNode：按 router 选出的 agentId 并行执行 agents 回调。
            // StaticRouter(['coding','finance'])：始终同时跑编码与财务两个方向。
            // 每个回调签名：(RouterContext $ctx, NeuronFactory $factory): array
            // 返回值写入 state.agentOutputs[agentId]。
            ->addAgentParallel('parallel_research', [
                'scheduler' => $scheduler,
                'router' => new StaticRouter(['coding', 'finance']),
                'agents' => [
                    'coding' => self::codingAgentHandler($useMockAgents),
                    'finance' => self::financeAgentHandler($useMockAgents),
                ],
            ])
            // 汇总节点：从 agentOutputs 提取 topic 列表，写入 data.summary。
            ->addNode('summary', new ClosureNode('summary', static function ($ctx, $state): NodeExecutionResult {
                unset($ctx);
                $outputs = $state->agentOutputs;
                $topics = array_map(
                    static fn ($item) => is_array($item) ? (string) ($item['topic'] ?? '') : '',
                    $outputs,
                );

                return NodeExecutionResult::success([
                    'summary' => [
                        'agentCount' => count($outputs),
                        'topics' => array_values(array_filter($topics)),
                    ],
                ]);
            }))
            // 并行研究完成后进入汇总。
            ->addEdge('parallel_research', 'summary');
    }

    /**
     * 编码方向 Agent 回调。
     *
     * @return callable(RouterContext, NeuronFactory): array<string, mixed>
     */
    private static function codingAgentHandler(bool $useMockAgents): callable
    {
        if ($useMockAgents) {
            return static function (RouterContext $ctx, NeuronFactory $factory): array {
                unset($factory);
                $query = (string) $ctx->state->get('query', 'Research topic');

                return [
                    'topic' => 'coding',
                    'content' => 'Mock coding research for: ' . $query,
                ];
            };
        }

        return static function (RouterContext $ctx, NeuronFactory $factory): array {
            // NeuronFactory::create 注入 Provider / MCP；promptKey 指向 state.query。
            $agent = $factory->create(CodingResearchAgent::class, $ctx->state, [
                'promptKey' => 'query',
            ]);
            $content = $agent->chat(new UserMessage((string) $ctx->state->get('query', 'Research topic')))
                ->getMessage()
                ->getContent();

            return ['topic' => 'coding', 'content' => $content];
        };
    }

    /**
     * 财务方向 Agent 回调。
     *
     * @return callable(RouterContext, NeuronFactory): array<string, mixed>
     */
    private static function financeAgentHandler(bool $useMockAgents): callable
    {
        if ($useMockAgents) {
            return static function (RouterContext $ctx, NeuronFactory $factory): array {
                unset($factory);
                $query = (string) $ctx->state->get('query', 'Research topic');

                return [
                    'topic' => 'finance',
                    'content' => 'Mock finance research for: ' . $query,
                ];
            };
        }

        return static function (RouterContext $ctx, NeuronFactory $factory): array {
            $agent = $factory->create(FinanceResearchAgent::class, $ctx->state, [
                'promptKey' => 'query',
            ]);
            $content = $agent->chat(new UserMessage((string) $ctx->state->get('query', 'Research topic')))
                ->getMessage()
                ->getContent();

            return ['topic' => 'finance', 'content' => $content];
        };
    }
}
