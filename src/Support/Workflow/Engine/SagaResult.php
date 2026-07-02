<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Saga 补偿执行结果 —— 记录已成功 compensate 的节点 id 列表。
 */
final class SagaResult
{
    /**
     * @param list<string> $compensatedNodeIds 已成功执行 compensate 的节点
     *                                       （按补偿执行顺序，即原 executedNodeIds 的逆序子集）
     */
    public function __construct(
        public readonly array $compensatedNodeIds,
    ) {
    }
}
