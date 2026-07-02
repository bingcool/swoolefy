<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Run 快照存储接口。
 * Phase 1：{@see InMemoryRunStore}；生产：Redis 实现。
 */
interface RunStoreInterface
{
    /** 保存或更新 Run 快照。 */
    public function save(WorkflowRun $run): void;

    /** 按 runId 查找，不存在返回 null。 */
    public function find(string $runId): ?WorkflowRun;
}
