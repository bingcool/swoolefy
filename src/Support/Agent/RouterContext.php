<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent;

use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * Agent 路由上下文 —— 供 {@see AgentRouterInterface} 读取 WorkflowState 决策。
 */
final class RouterContext
{
    /**
     * @param list<string>         $availableAgents 当前节点注册的全部 agentId
     * @param array<string, mixed> $meta            扩展元数据
     */
    public function __construct(
        public readonly string $runId,
        public readonly WorkflowState $state,
        public readonly array $availableAgents = [],
        public readonly float $timeoutSeconds = 60.0,
        public readonly array $meta = [],
    ) {
    }
}
