<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

/** 边类型：无条件固定边 / 单条条件边。 */
enum EdgeType: string
{
    case ALWAYS = 'always';
    case CONDITIONAL = 'conditional';
}
