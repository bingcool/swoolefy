<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Definition;

/** DAG 有向边：源节点 → 目标节点，可选条件。 */
final class Edge
{
    public function __construct(
        /** 源节点 id。 */
        public readonly string $from,
        /** 目标节点 id。 */
        public readonly string $to,
        public readonly EdgeType $type = EdgeType::ALWAYS,
        /** 条件边时的求值描述符。 */
        public readonly ?EdgeCondition $condition = null,
    ) {
    }
}
