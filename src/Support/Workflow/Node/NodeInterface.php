<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Throwable;

/**
 * 工作流节点完整生命周期接口。
 *
 * 引擎应优先调用 {@see AbstractNode::run()} 以保证钩子顺序；
 * 直接调用 execute() 会跳过部分生命周期。
 *
 * @see docs/swoolefyAI.md §4.2
 */
interface NodeInterface
{
    /** 节点在 DAG 中的唯一标识。 */
    public function id(): string;

    /** 每次执行（含重试）开始前调用。 */
    public function beforeExecute(RunContext $ctx, WorkflowState $state): void;

    /** 核心业务逻辑，返回执行结果驱动路由。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult;

    /** 仅 SUCCESS 时调用。 */
    public function afterExecute(RunContext $ctx, WorkflowState $state, NodeExecutionResult $result): void;

    /** status == RETRY 时调用，attempt 为当前重试次数。 */
    public function onRetry(RunContext $ctx, WorkflowState $state, int $attempt, ?Throwable $e): void;

    /** 超时守卫触发时调用（Phase 3 TimeoutGuard）。 */
    public function onTimeout(RunContext $ctx, WorkflowState $state): void;

    /** status == WAITING 时调用（HITL / PauseNode）。 */
    public function onPause(RunContext $ctx, WorkflowState $state, NodeExecutionResult $result): void;

    /** WorkflowEngine::resume() 合并 feedback 后调用。 */
    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void;

    /** status == FAILED 或抛异常时调用。 */
    public function onFail(RunContext $ctx, WorkflowState $state, ?Throwable $e): void;

    /** Saga 补偿，按拓扑逆序执行（Phase 4）。 */
    public function compensate(RunContext $ctx, WorkflowState $state): void;
}
