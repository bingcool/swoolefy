<?php

declare(strict_types=1);

namespace Test\Module\Order;

use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Order\Workflow\OrderProcessingWorkflow;
use Test\Module\Order\Workflow\OrderSagaWorkflow;

/**
 * Order 模块本地工作流装配 —— 不依赖 Test\Module\Workflow\WorkflowService。
 *
 * 职责：
 *   1. 注册本模块 workflowId（order_processing、order_saga）到独立 Registry
 *   2. 惰性创建 NeuronFactory（供 AI 决策节点）
 *   3. Engine 与本 Registry 绑定同一 RunStore（谁启动谁查询）
 *
 * Demo / status / resume 必须走本类；统一 API 经 WorkflowService::engineFor* 路由到此。
 *
 * @see OrderWorkflowDemoController
 * @see OrderProcessingWorkflow
 * @see OrderSagaWorkflow
 */
final class OrderWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    private static ?NeuronFactory $neuronFactory = null;

    /** 本模块工作流注册表（首次调用时注册 order_*）。 */
    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'order_processing',
                static fn () => OrderProcessingWorkflow::definition(self::neuronFactory()),
            );
            self::$registry->register(
                'order_saga',
                static fn () => OrderSagaWorkflow::definition(),
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

    /** Order 专用 Neuron 工厂（无 MCP 依赖；演示可用 mockDecision 绕过 LLM）。 */
    public static function neuronFactory(): NeuronFactory
    {
        if (self::$neuronFactory === null) {
            self::$neuronFactory = new NeuronFactory();
        }

        return self::$neuronFactory;
    }

    /** 重置单例（单测隔离）。 */
    public static function reset(): void
    {
        self::$registry = null;
        self::$neuronFactory = null;
        WorkflowComponentFactory::resetRunStores();
    }
}
