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
 *   - RunStoreInterface：save / revision CAS / find
 *   - PauseTaskQueryableInterface：listWaiting（Phase 3 HITL）
 *   - all()：单测列举全部 Run（Phase 4 Saga 断言）
 *
 * CAS 比对的是 persistedStatus / persistedRevision，与 Run 对象内存态解耦。
 * find() 返回同一引用：并发 CAS 测试必须准备两份 WorkflowRun 快照分别调用，
 * 不能两人改同一对象再比 revision。
 */
final class InMemoryRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /** @var array<string, WorkflowRun> runId => 快照 */
    private array $runs = [];

    /** @var array<string, RunStatus> runId => 上次持久化时的 status（CAS 用） */
    private array $persistedStatus = [];

    /** @var array<string, int> runId => 上次持久化时的 revision（CAS 用） */
    private array $persistedRevision = [];

    /** {@inheritdoc} 覆盖写入，记下当前对象上的 status + revision（不自动 +1）。 */
    public function save(WorkflowRun $run): void
    {
        $this->runs[$run->runId] = $run;
        $this->persistedStatus[$run->runId] = $run->status;
        $this->persistedRevision[$run->runId] = $run->revision;
    }

    /** {@inheritdoc} */
    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool
    {
        return $this->casWrite($run, null, $expectedRevision, bumpRevision: true);
    }

    /** {@inheritdoc} */
    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool {
        return $this->casWrite($run, $expectedStatus, $expectedRevision, bumpRevision: true);
    }

    /**
     * 内存 CAS —— 比对 persistedStatus 与 expectedStatus（不比对 revision）。
     *
     * {@inheritdoc}
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        return $this->casWrite($run, $expectedStatus, null, bumpRevision: false);
    }

    /**
     * @param RunStatus|null $expectedStatus  null 表示不比对 status
     * @param int|null       $expectedRevision null 表示不比对 revision
     */
    private function casWrite(
        WorkflowRun $run,
        ?RunStatus $expectedStatus,
        ?int $expectedRevision,
        bool $bumpRevision,
    ): bool {
        if (!isset($this->runs[$run->runId])) {
            return false;
        }

        if ($expectedStatus !== null && ($this->persistedStatus[$run->runId] ?? null) !== $expectedStatus) {
            return false;
        }

        if ($expectedRevision !== null && ($this->persistedRevision[$run->runId] ?? 0) !== $expectedRevision) {
            return false;
        }

        if ($bumpRevision && $expectedRevision !== null) {
            $run->revision = $expectedRevision + 1;
        }

        $this->runs[$run->runId] = $run;
        $this->persistedStatus[$run->runId] = $run->status;
        $this->persistedRevision[$run->runId] = $run->revision;

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
