<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 单次工作流运行的持久化快照。
 *
 * Phase 1 存内存；生产序列化 WorkflowState::toArray() 到 Redis。
 * Phase 4：executedNodeIds 供 SagaCoordinator 逆序补偿。
 */
final class WorkflowRun
{
    public function __construct(
        public readonly string $runId,
        public readonly CompiledWorkflow $compiled,
        public RunStatus $status,
        public WorkflowState $state,
        public readonly string $createdAt,
        public string $updatedAt,
        /** 当前正在执行的节点 id。 */
        public ?string $currentNodeId = null,
        /** WAITING 时暂停所在的节点 id。 */
        public ?string $pauseNodeId = null,
        /** FAILED / COMPENSATED 时的错误信息。 */
        public ?string $error = null,
        /** 最近一次条件边选中的 target（API 响应用）。 */
        public ?string $lastRoutedEdge = null,
        /**
         * 已成功执行（SUCCESS）的节点 id 列表，按执行顺序追加。
         * Saga 失败时按此列表逆序调用 compensate()。
         *
         * @var list<string>
         */
        public array $executedNodeIds = [],
    ) {
    }
}
