<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent\Router;

use Swoolefy\Support\Agent\AgentRouterInterface;
use Swoolefy\Support\Agent\RouterContext;

/**
 * 加权随机路由 —— 按 weight 概率独立选中各 Agent。
 *
 * weight 范围建议 0.0 ~ 1.0；>= 1.0 表示必选中。
 */
final class WeightedRouter implements AgentRouterInterface
{
    /** @param array<string, float> $weights agentId => weight */
    public function __construct(
        private readonly array $weights,
    ) {
    }

    /** {@inheritdoc} */
    public function route(RouterContext $ctx): array
    {
        unset($ctx);
        $selected = [];

        foreach ($this->weights as $agentId => $weight) {
            if ($weight >= 1.0) {
                $selected[] = $agentId;
                continue;
            }

            if ($weight <= 0) {
                continue;
            }

            if (mt_rand() / mt_getrandmax() <= $weight) {
                $selected[] = $agentId;
            }
        }

        if ($selected === [] && $this->weights !== []) {
            $selected[] = (string) array_key_first($this->weights);
        }

        return $selected;
    }
}
