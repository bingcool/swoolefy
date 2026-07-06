<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 轮询路由 —— 按调用顺序循环返回单个 Agent（负载均衡）。
 */
final class RoundRobinRouter implements AgentRouterInterface
{
    private int $cursor = 0;

    /** @param list<string> $agentIds */
    public function __construct(
        private readonly array $agentIds,
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        unset($ctx);
        if ($this->agentIds === []) {
            return [];
        }

        $index = $this->cursor % count($this->agentIds);
        $this->cursor++;

        return [$this->agentIds[$index]];
    }
}
