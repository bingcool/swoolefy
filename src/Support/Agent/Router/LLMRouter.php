<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * LLM / 规则混合路由 —— Phase 3 可插拔 Agent 选择。
 *
 * 优先级：
 *   1. 自定义 selector callable
 *   2. 关键词启发式（单测 / 无 API Key 回退）
 */
final class LLMRouter implements AgentRouterInterface
{
    /**
     * @param list<string>                              $availableAgents
     * @param callable(RouterContext): list<string>|null $selector
     */
    public function __construct(
        private readonly array $availableAgents,
        private $selector = null,
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        if ($this->selector !== null) {
            $selected = ($this->selector)($ctx);

            return is_array($selected) ? array_values($selected) : [];
        }

        return $this->heuristicRoute($ctx);
    }

    /** @return list<string> */
    private function heuristicRoute(RouterContext $ctx): array
    {
        $query = strtolower((string) $ctx->state->get('query', ''));

        if ($query === '') {
            return $this->availableAgents;
        }

        $selected = [];
        if (str_contains($query, 'code') || str_contains($query, 'api') || str_contains($query, 'github')) {
            $selected[] = 'coding';
        }
        if (str_contains($query, 'finance') || str_contains($query, 'cost') || str_contains($query, 'budget')) {
            $selected[] = 'finance';
        }

        if ($selected === []) {
            return $this->availableAgents;
        }

        return array_values(array_intersect($this->availableAgents, $selected));
    }
}
