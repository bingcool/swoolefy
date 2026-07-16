<?php

declare(strict_types=1);

namespace Test\Module\Rag;

use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Test\Module\Rag\Workflow\RagQaWorkflow;

/**
 * Rag 模块本地工作流装配 —— rag_qa 的 Registry / Engine 归属本模块。
 *
 * RunStore 与 {@see registry()} 绑定；Demo / status 必须走本类 engine()。
 *
 * @see \Test\Module\Rag\Controller\RagController
 */
final class RagWorkflowService
{
    private static ?WorkflowRegistry $registry = null;

    public static function registry(): WorkflowRegistry
    {
        if (self::$registry === null) {
            self::$registry = new WorkflowRegistry();
            self::$registry->register(
                'rag_qa',
                static fn () => RagQaWorkflow::definition(
                    RagService::instance()->retrievalService(),
                ),
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
