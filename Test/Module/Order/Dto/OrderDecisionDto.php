<?php

declare(strict_types=1);

namespace Test\Module\Order\Dto;

/**
 * 订单 AI 决策结构化输出 DTO。
 *
 * 由 AINode 写入 state.data.decision，供以下场景消费：
 *   - 条件边：data['decision']['approved']、data['decision']['confidence']
 *   - 业务节点：$state->dto(OrderDecisionDto::class)
 *
 * @see swoolefyAI.md §4.8
 */
final class OrderDecisionDto
{
    /** 是否批准订单。 */
    public bool $approved;

    /** 置信度 0~1。 */
    public float $confidence;

    /** 决策理由说明。 */
    public string $reason;
}
