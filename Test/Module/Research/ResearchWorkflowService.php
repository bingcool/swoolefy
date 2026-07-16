<?php

declare(strict_types=1);

namespace Test\Module\Research;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Research\Controller\ResearchWorkflowDemoController;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

/**
 * Research 模块本地工作流装配 —— 与 Order/Outdoor 同一模式。
 *
 * 职责：
 *   1. 注册本模块 workflowId（multi_agent_research、mcp_research）到独立 Registry
 *   2. 惰性创建 NeuronFactory / AgentScheduler
 *   3. Engine 与本 Registry 绑定同一 RunStore（谁启动谁查询）
 *
 * Demo / status 必须走本类；统一 API 经 WorkflowService::engineFor* 路由到此。
 *
 * @see ResearchWorkflowDemoController
 * @see MultiAgentResearchWorkflow
 * @see McpResearchWorkflow
 */
final class ResearchWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?NeuronFactory $neuronFactory = null;

    private static ?AgentScheduler $agentScheduler = null;

    /** 本模块工作流注册表（首次调用时注册 research_*）。 */
    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'multi_agent_research',
                static fn () => MultiAgentResearchWorkflow::definition(self::agentScheduler()),
            );
            self::$registry->register(
                'mcp_research',
                static fn () => McpResearchWorkflow::definition(self::neuronFactory()),
            );
        }

        return self::$registry;
    }

    /**
     * 生产级 Engine：RunStore 与模块 Registry 绑定，供 status 水合。
     */
    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    /** Research 专用 Neuron 工厂（无 MCP 依赖；MCP Demo 可用 stub executor）。 */
    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory();
        }

        return self::$neuronFactory;
    }

    /** 多 Agent 并行调度器（multi_agent_research 依赖）。 */
    public static function agentScheduler(): AgentScheduler
    {
        if (self::$agentScheduler === null) {
            self::$agentScheduler = new AgentScheduler(self::neuronFactory());
        }

        return self::$agentScheduler;
    }

    /** 重置单例（单测隔离）。 */
    public static function reset(): void
    {
        self::$registry = null;
        self::$neuronFactory = null;
        self::$agentScheduler = null;
        WorkflowComponentFactory::resetRunStores();
    }
}
