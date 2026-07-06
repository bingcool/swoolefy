<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Node;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 闭包节点 —— 用 callable 快速定义节点逻辑（测试/简单脚本）。
 */
final class ClosureNode extends AbstractNode
{
    /** @var callable(RunContext, WorkflowState): NodeExecutionResult */
    private $handler;

    /**
     * @param callable(RunContext, WorkflowState): NodeExecutionResult $handler 节点执行闭包
     */
    public function __construct(
        string $nodeId,
        callable $handler,
    ) {
        parent::__construct($nodeId);
        $this->handler = $handler;
    }

    /** {@inheritdoc} 委托给构造时传入的闭包。 */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return ($this->handler)($ctx, $state);
    }
}
