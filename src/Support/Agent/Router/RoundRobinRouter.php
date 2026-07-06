<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 轮询路由 —— 按当前 WorkflowState.meta 记录游标，循环返回单个 Agent。
 *
 * 注意：Router 实例可能被 CompiledWorkflow 缓存并在同一 Worker 内跨 Run 复用。
 * 因此这里不能把 cursor 放在 Router 属性上，否则常驻进程 + 协程并发会导致
 * 不同请求/租户互相影响路由选择。游标写入 WorkflowState.meta 后，状态边界
 * 收敛到单次 Run，持久化/恢复也能保持一致。
 */
final class RoundRobinRouter implements AgentRouterInterface
{
    /** @param list<string> $agentIds */
    public function __construct(
        private readonly array $agentIds,
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        if ($this->agentIds === []) {
            return [];
        }

        $key = $this->cursorKey();
        $cursor = $ctx->state->meta[$key] ?? 0;
        $cursor = is_int($cursor) && $cursor >= 0 ? $cursor : 0;
        $index = $cursor % count($this->agentIds);
        $ctx->state->meta[$key] = $cursor + 1;

        return [$this->agentIds[$index]];
    }

    private function cursorKey(): string
    {
        return 'agent.round_robin.cursor.' . substr(sha1(implode("\0", $this->agentIds)), 0, 12);
    }
}
