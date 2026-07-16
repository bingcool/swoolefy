<?php

declare(strict_types=1);

namespace Test\Module\Contract;

use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Contract\Workflow\ContractReviewWorkflow;

/**
 * Contract 模块本地工作流装配 —— 与 Order/Outdoor 同一模式。
 *
 * 职责：
 *   1. 注册本模块 workflowId（contract_review）到独立 Registry
 *   2. Engine 与本 Registry 绑定同一 RunStore（谁启动谁查询）
 *
 * Demo / status / resume 必须走本类；统一 API 经 WorkflowService::engineFor* 路由到此。
 *
 * @see ContractReviewWorkflow
 */
final class ContractWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'contract_review',
                static fn () => ContractReviewWorkflow::definition(),
            );
        }

        return self::$registry;
    }

    public static function engine(?WorkflowEventDispatcherInterface $events = null): WorkflowEngine
    {
        return WorkflowComponentFactory::engine(self::registry(), events: $events);
    }

    public static function reset(): void
    {
        self::$registry = null;
        WorkflowComponentFactory::resetRunStores();
    }
}
