<?php

declare(strict_types=1);

namespace Swoolefy\Support\Tests\Fixtures;

/**
 * Support 单测用决策 DTO（不依赖 Test\Module）。
 *
 * 字段与业务 OrderDecisionDto 对齐，供 AINode structured / 条件边使用。
 */
final class DecisionDto
{
    public bool $approved;

    public float $confidence;

    public string $reason;
}
