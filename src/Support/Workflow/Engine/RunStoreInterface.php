<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Run 快照存储接口。
 */
interface RunStoreInterface
{
    /** 保存或更新 Run 快照。 */
    public function save(WorkflowRun $run): void;

    /**
     * 仅当当前持久化状态为 $expectedStatus 时写入（resume CAS）。
     *
     * @return bool 写入成功 true；状态已变化 false
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool;

    /** 按 runId 查找，不存在返回 null。 */
    public function find(string $runId): ?WorkflowRun;
}
