<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 成本感知 Agent 路由 —— 在单次 Run 预算内选择 **token 成本最低** 的单个 Agent。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   多模型 / 多 Agent 单价不同时，按预估 token 花销选最便宜且不超预算的一个，
 *   控制单次并行节点的费用上限。
 *
 * ---------------------------------------------------------------------------
 * 算法
 * ---------------------------------------------------------------------------
 *
 *   1. 估算本次请求 token 数
 *        - 优先 state.estimatedTokens（业务可上游写入）
 *        - 否则用 state.query 长度 × 0.75（中英混合保守估计）
 *        - query 为空则默认 500
 *   2. 对每个 Agent：estimatedCost = (tokens / 1000) × costPer1kTokens[agentId]
 *   3. 跳过 estimatedCost > budgetUsd 或 rate < 0 的项
 *   4. 在剩余项中取 estimatedCost 最小者
 *   5. 若全部超预算 → 降级为 map 中第一个 agentId（避免空路由）
 *
 * 返回值始终是 0 或 1 个元素的 list（与 AgentScheduler「选中列表」约定一致）。
 *
 * 与 {@see LLMRouter} / {@see WeightedRouter} 可互换，均实现 {@see AgentRouterInterface}。
 *
 * @see docs/SwoolefyAI.md §4.3 CostAwareRouter
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
     * 返回预算内成本最低的单个 agentId。
     *
     * {@inheritdoc}
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
            // 负单价视为配置错误，跳过该 Agent。
            if ($rate < 0) {
                continue;
            }

            $estimated = ($tokens / 1000) * $rate;
            // 超预算：本轮不考虑。
            if ($estimated > $this->budgetUsd) {
                continue;
            }

            // 严格小于才更新，并列时保留先声明的 Agent（稳定、可预期）。
            if ($bestCost === null || $estimated < $bestCost) {
                $bestCost = $estimated;
                $bestAgent = $agentId;
            }
        }

        // 全部超预算：降级选 map 中第一个，避免 Scheduler 空跑。
        if ($bestAgent === null) {
            $bestAgent = (string) array_key_first($this->costPer1kTokens);
        }

        return [$bestAgent];
    }

    /**
     * Token 估算 —— 优先读 WorkflowState 显式字段，否则启发式。
     *
     * 这不是精确 tokenizer；仅用于相对比较各 Agent 成本，允许业务写入 estimatedTokens 校准。
     */
    private function estimateTokens(RouterContext $ctx): float
    {
        $fromState = $ctx->state->get('estimatedTokens');
        if (is_numeric($fromState)) {
            // 至少 1，避免零 token 导致所有成本为 0、比较无意义。
            return max(1.0, (float) $fromState);
        }

        $query = (string) $ctx->state->get('query', '');
        if ($query === '') {
            return 500.0;
        }

        // 英文约 4 字符/token；中文更密，0.75 为偏保守（估高一点 → 更倾向便宜模型）。
        return max(100.0, strlen($query) * 0.75);
    }
}
