<?php

declare(strict_types=1);

namespace Test\Module\Outdoor;

use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Outdoor\Workflow\OutdoorCyclingWorkflow;

/**
 * Outdoor 模块本地工作流装配 —— 不依赖 Test\Module\Workflow\WorkflowService。
 *
 * 职责：
 *   1. 注册本模块 workflowId（outdoor_cycling）到独立 Registry
 *   2. 惰性创建 NeuronFactory / AgentScheduler
 *   3. 按 workflow.php 装配 Engine（跨 Worker status/resume）
 *
 * Demo 控制器与模块单测应通过本类启动 / 查询 Run。
 *
 * @see OutdoorWorkflowDemoController
 * @see OutdoorCyclingWorkflow
 */
final class OutdoorWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?NeuronFactory $neuronFactory = null;

    private static ?AgentScheduler $agentScheduler = null;

    /** 本模块工作流注册表（首次调用时注册 outdoor_cycling）。 */
    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'outdoor_cycling',
                static fn () => OutdoorCyclingWorkflow::definition(self::agentScheduler()),
            );
        }

        return self::$registry;
    }

    /**
     * 生产级 Engine：RunStore 与模块 Registry 绑定，供 status / resume 水合。
     */
    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    /** Outdoor 专用 Neuron 工厂（无 MCP 依赖，演示默认即可）。 */
    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory();
        }

        return self::$neuronFactory;
    }

    /** 多 Agent 并行调度器（parallel_prepare 节点依赖）。 */
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
    }
}
