<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 加权随机路由 —— 按 weight 对每个 Agent **独立**做伯努利试验，可多选。
 *
 * ---------------------------------------------------------------------------
 * 适用场景
 * ---------------------------------------------------------------------------
 *
 *   A/B、灰度、探索：希望某些 Agent 以概率 p 参与并行，而不是每次全开或只开一个。
 *
 * ---------------------------------------------------------------------------
 * weight 语义
 * ---------------------------------------------------------------------------
 *
 *   - weight >= 1.0  → 必选中
 *   - weight <= 0    → 永不选中
 *   - (0, 1)         → 以该概率选中（mt_rand 均匀 [0,1]）
 *
 * 建议业务侧把权重规范到 0.0 ~ 1.0；大于 1 仅作「强制开启」快捷写法。
 *
 * ---------------------------------------------------------------------------
 * 兜底
 * ---------------------------------------------------------------------------
 *
 * 若本轮一个都没抽中且 weights 非空 → 强制选 map 中 **第一个** agentId，
 * 避免 Scheduler 收到空列表导致「空跑成功」。
 *
 * 本实现不读 RouterContext（与 state 无关的纯随机）；保留 $ctx 参数以符合接口。
 */
final class WeightedRouter implements AgentRouterInterface
{
    /**
     * @param array<string, float> $weights agentId => 选中概率（或 >=1 必选）
     */
    public function __construct(
        private readonly array $weights,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return list<string> 0..N 个 agentId（声明顺序）
     */
    public function route(RouterContext $ctx): array
    {
        // 接口要求接收 ctx；加权策略不依赖 Run / State。
        unset($ctx);
        $selected = [];

        foreach ($this->weights as $agentId => $weight) {
            // 必选：跳过随机，直接加入。
            if ($weight >= 1.0) {
                $selected[] = $agentId;
                continue;
            }

            // 权重非正：跳过。
            if ($weight <= 0) {
                continue;
            }

            // 均匀 [0,1] 与 weight 比较；注意 mt_rand 在多 Worker 下各自独立，无跨进程协调。
            if (mt_rand() / mt_getrandmax() <= $weight) {
                $selected[] = $agentId;
            }
        }

        // 全未命中时的保底，保证至少有一个 Agent 可执行。
        if ($selected === [] && $this->weights !== []) {
            $selected[] = (string) array_key_first($this->weights);
        }

        return $selected;
    }
}
