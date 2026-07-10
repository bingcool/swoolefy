<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/**
 * Phase 1 内存 Run 存储（单进程/单测）。
 *
 * 能力：
 *   - RunStoreInterface：save / find
 *   - PauseTaskQueryableInterface：listWaiting（Phase 3 HITL）
 *   - all()：单测列举全部 Run（Phase 4 Saga 断言）
 *
 * 生产替换：Redis RunStore + 同样实现 PauseTaskQueryableInterface。
 */
final class InMemoryRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /** @var array<string, WorkflowRun> runId => 快照 */
    private array $runs = [];

    /** @var array<string, RunStatus> runId => 上次持久化时的 status（CAS 用） */
    private array $persistedStatus = [];

    /** {@inheritdoc} 覆盖写入，保留同一 runId 最新状态。 */
    public function save(WorkflowRun $run): void
    {
        $this->runs[$run->runId] = $run;
        $this->persistedStatus[$run->runId] = $run->status;
    }

    /**
     * 内存 CAS —— 比对 persistedStatus 与 expectedStatus。
     *
     * persistedStatus 在每次 save/saveIfStatus 成功时更新，与 Run 对象内存态解耦，
     * 模拟 DB 层「持久化 status」语义，供单测 resume 竞态场景。
     *
     * {@inheritdoc}
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        if (!isset($this->runs[$run->runId])) {
            return false;
        }

        if (($this->persistedStatus[$run->runId] ?? null) !== $expectedStatus) {
            return false;
        }

        $this->runs[$run->runId] = $run;
        $this->persistedStatus[$run->runId] = $run->status;

        return true;
    }

    /** {@inheritdoc} */
    public function find(string $runId): ?WorkflowRun
    {
        return $this->runs[$runId] ?? null;
    }

    /** {@inheritdoc} 按 assignee 过滤 PauseNode 写入的 nodeOutputs.assignee。 */
    public function listWaiting(?string $assignee = null): array
    {
        $waiting = [];
        foreach ($this->runs as $run) {
            if ($run->status !== RunStatus::WAITING) {
                continue;
            }

            if ($assignee !== null && $assignee !== '') {
                $output = $run->state->outputOf((string) $run->pauseNodeId) ?? [];
                $taskAssignee = is_array($output) ? ($output['assignee'] ?? null) : null;
                if ($taskAssignee !== $assignee) {
                    continue;
                }
            }

            $waiting[] = $run;
        }

        return $waiting;
    }

    /**
     * 列出全部 Run（单测/调试）。
     *
     * @return list<WorkflowRun>
     */
    public function all(): array
    {
        return array_values($this->runs);
    }
}
