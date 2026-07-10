<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 轮询路由 —— 按 WorkflowState.meta 中的游标，每次只返回 **一个** Agent。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   负载分摊：多次进入同一并行节点（或同 Run 内多次 route）时轮流选 Agent，
 *   避免总是打到列表第一个。
 *
 * ---------------------------------------------------------------------------
 * 游标为何写在 state.meta，而不是 Router 属性
 * ---------------------------------------------------------------------------
 *
 * Router 实例可能被 CompiledWorkflow / 组件容器缓存，并在同一 Worker 内跨 Run、
 * 跨协程复用。若 cursor 放在 `$this->cursor`：
 *   - 不同租户 / 请求会互相推进游标
 *   - 协程并发下非原子自增会错乱
 *
 * 写入 `$ctx->state->meta[$key]` 后：
 *   - 边界收敛到 **单次 Run** 的 WorkflowState
 *   - 快照持久化 / resume 后游标仍一致
 *
 * meta 键含 agentIds 指纹，避免同一 state 上挂多个 RoundRobinRouter 实例时撞键。
 *
 * @see AgentRouterInterface
 */
final class RoundRobinRouter implements AgentRouterInterface
{
    /**
     * @param list<string> $agentIds 参与轮询的有序列表；空则 route() 返回 []
     */
    public function __construct(
        private readonly array $agentIds,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string> 恰好 0 或 1 个 agentId
     */
    public function route(RouterContext $ctx): array
    {
        if ($this->agentIds === []) {
            return [];
        }

        $key = $this->cursorKey();
        // 缺省从 0 开始；非法值（非 int / 负）重置为 0，避免 % 运算异常。
        $cursor = $ctx->state->meta[$key] ?? 0;
        $cursor = is_int($cursor) && $cursor >= 0 ? $cursor : 0;

        // 取模得到本轮下标，再把游标 +1 写回，供同 Run 下次 route 使用。
        $index = $cursor % count($this->agentIds);
        $ctx->state->meta[$key] = $cursor + 1;

        return [$this->agentIds[$index]];
    }

    /**
     * 游标在 state.meta 中的键名。
     *
     * 用 agentIds 的稳定指纹区分不同轮询集合，防止多 Router 共用一个 meta 键。
     */
    private function cursorKey(): string
    {
        // implode + sha1：顺序敏感；同一列表始终同一键。
        return 'agent.round_robin.cursor.' . substr(sha1(implode("\0", $this->agentIds)), 0, 12);
    }
}
