<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 成本感知 Agent 路由 —— 在单次 Run 预算内选择 token 成本最低的 Agent。
 *
 * 算法：
 *   1. 估算本次请求 token 数（state.estimatedTokens 优先，否则 query 长度 × 0.75）
 *   2. 对每个 Agent 计算 estimatedCost = (tokens/1000) × costPer1kTokens
 *   3. 跳过 estimatedCost > budgetUsd 的 Agent
 *   4. 在剩余 Agent 中选 cost 最低者；若全部超预算则回退到 map 中第一个
 *
 * 与 LLMRouter / WeightedRouter 可互换，均实现 {@see AgentRouterInterface}。
 *
 * @see swoolefyAI.md §4.6 CostAwareRouter
 */
final class CostAwareRouter implements AgentRouterInterface
{
    /**
     * @param array<string, float> $costPer1kTokens agentId => 每 1k token 美元单价
     * @param float                $budgetUsd       单次路由预算上限（USD）
     */
    public function __construct(
        private readonly array $costPer1kTokens,
        private readonly float $budgetUsd = 1.0,
    ) {
    }

    /**
     * 返回预算内成本最低的单个 agentId（list 形式与 AgentScheduler 约定一致）。
     *
     * @return list<string>
     */
    public function route(RouterContext $ctx): array
    {
        if ($this->costPer1kTokens === []) {
            return [];
        }

        $tokens = $this->estimateTokens($ctx);
        $bestAgent = null;
        $bestCost = null;

        foreach ($this->costPer1kTokens as $agentId => $rate) {
            if ($rate < 0) {
                continue;
            }
            $estimated = ($tokens / 1000) * $rate;
            if ($estimated > $this->budgetUsd) {
                continue;
            }
            if ($bestCost === null || $estimated < $bestCost) {
                $bestCost = $estimated;
                $bestAgent = $agentId;
            }
        }

        // 全部超预算：降级选 map 中第一个 Agent，避免空路由
        if ($bestAgent === null) {
            $bestAgent = (string) array_key_first($this->costPer1kTokens);
        }

        return [$bestAgent];
    }

    /**
     * Token 估算 —— 优先读 WorkflowState 显式字段，否则启发式。
     */
    private function estimateTokens(RouterContext $ctx): float
    {
        $fromState = $ctx->state->get('estimatedTokens');
        if (is_numeric($fromState)) {
            return max(1.0, (float) $fromState);
        }

        $query = (string) $ctx->state->get('query', '');
        if ($query === '') {
            return 500.0;
        }

        // 英文约 4 字符/token；中文更密，0.75 为保守估计
        return max(100.0, strlen($query) * 0.75);
    }
}
