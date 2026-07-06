<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * 可查询 HITL 暂停任务的 RunStore 扩展接口。
 */
interface PauseTaskQueryableInterface
{
    /**
     * 列出 WAITING 状态的 Run，可按 assignee 过滤。
     *
     * @return list<WorkflowRun>
     */
    public function listWaiting(?string $assignee = null): array;
}
