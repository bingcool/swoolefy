<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 固定 Agent 列表路由 —— 始终返回声明的 agentId 集合。
 */
final class StaticRouter implements AgentRouterInterface
{
    /**
     * @param list<string> $agentIds
     */
    public function __construct(
        private readonly array $agentIds = [],
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        if ($this->agentIds !== []) {
            return $this->agentIds;
        }

        return $ctx->availableAgents;
    }
}
