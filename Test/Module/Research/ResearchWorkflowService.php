<?php

declare(strict_types=1);

namespace Test\Module\Research;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;

/**
 * Research 模块本地工作流装配 —— 不依赖中央 Runtime Engine 双写。
 *
 * 拥有 workflowId：multi_agent_research、mcp_research。
 * RunStore 与本模块 {@see registry()} 绑定；status/resume 必须走本类 engine()。
 *
 * @see ResearchWorkflowDemoController
 */
final class ResearchWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?NeuronFactory $neuronFactory = null;

    private static ?AgentScheduler $agentScheduler = null;

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

    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory();
        }

        return self::$neuronFactory;
    }

    public static function agentScheduler(): AgentScheduler
    {
        if (self::$agentScheduler === null) {
            self::$agentScheduler = new AgentScheduler(self::neuronFactory());
        }

        return self::$agentScheduler;
    }

    public static function reset(): void
    {
        self::$registry = null;
        self::$neuronFactory = null;
        self::$agentScheduler = null;
        WorkflowComponentFactory::resetRunStores();
    }
}
