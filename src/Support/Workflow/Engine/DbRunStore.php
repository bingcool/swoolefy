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

use PDO;
use PDOException;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowRuntimeException;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Throwable;

/**
 * 关系库 Run 快照存储 —— 生产级跨 Worker 持久化。
 *
 * 能力：
 *   - RunStoreInterface：幂等 UPSERT save / revision CAS / find
 *   - PauseTaskQueryableInterface：按 status + assignee 索引查询 WAITING
 *
 * PDO 可直接注入，或注入 `Closure(): PDO`（Factory 生产路径用后者：
 * 每次 IO 从当前 Application 取连接，避免进程级 Store 冻死首次 cid 的 PDO）。
 *
 * 高可用要点：
 *   - 使用事务提交快照，避免半写入
 *   - UPSERT 幂等，Worker 重试安全
 *   - 查询列（status / revision / assignee / updated_at）冗余存储，避免扫 JSON
 *   - payload 存完整 {@see WorkflowRunSnapshot}，恢复时从 Registry 取 CompiledWorkflow
 *   - 须预执行 Schema/workflow_runs.sql 建表
 *   - CAS 用单条 UPDATE … WHERE，禁止 GET→PHP 判断→SET
 *   - 死锁最多重试 3 次；rowCount=0（条件不匹配）不得重试
 *
 * 支持驱动：mysql / mariadb / sqlite（单测，表由测试侧预装）
 */
final class DbRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /**
     * @param PDO|\Closure(): PDO $pdo 连接或按调用解析的 resolver
     */
    public function __construct(
        private readonly PDO|\Closure $pdo,
        private readonly WorkflowRegistry $registry,
        private readonly string $table = 'workflow_runs',
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table)) {
            throw new WorkflowException("Invalid workflow runs table name [{$this->table}]");
        }
        if ($this->pdo instanceof PDO) {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    /** {@inheritdoc} */
    public function save(WorkflowRun $run): void
    {
        $payload = WorkflowRunSnapshot::encode($run);
        $pdo = $this->connection();
        $sql = $this->upsertSql((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $params = $this->writeParams($run, $payload, $run->revision);

        $attempts = 0;
        $lastError = null;
        while ($attempts < 3) {
            ++$attempts;
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $pdo->commit();

                return;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $lastError = $e;
                if ($attempts < 3 && $this->isRetryable($e)) {
                    usleep(50_000 * $attempts);
                    continue;
                }
                break;
            }
        }

        throw new WorkflowRuntimeException(
            'Failed to persist workflow run [' . $run->runId . ']: ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError,
        );
    }

    /** {@inheritdoc} */
    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool
    {
        return $this->casUpdate(
            $run,
            $expectedRevision + 1,
            'AND revision = :expected_revision',
            [':expected_revision' => $expectedRevision],
            $expectedRevision,
        );
    }

    /** {@inheritdoc} */
    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool {
        return $this->casUpdate(
            $run,
            $expectedRevision + 1,
            'AND status = :expected_status AND revision = :expected_revision',
            [
                ':expected_status' => $expectedStatus->value,
                ':expected_revision' => $expectedRevision,
            ],
            $expectedRevision,
        );
    }

    /**
     * CAS 条件更新 —— 仅比对 status。
     *
     * SQL 语义：UPDATE ... WHERE run_id=? AND status=:expected_status
     * rowCount=0 表示已被其他 Worker resume/cancel，返回 false。
     *
     * {@inheritdoc}
     */
    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        return $this->casUpdate(
            $run,
            $run->revision,
            'AND status = :expected_status',
            [':expected_status' => $expectedStatus->value],
            null,
        );
    }

    /**
     * @param array<string, mixed> $extraWhereParams
     */
    private function casUpdate(
        WorkflowRun $run,
        int $persistRevision,
        string $whereExtra,
        array $extraWhereParams,
        ?int $bumpFromRevision,
    ): bool {
        $payload = WorkflowRunSnapshot::encode($run, $persistRevision);
        $pdo = $this->connection();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $this->updateSql($driver, $whereExtra);
        $params = $this->writeParams($run, $payload, $persistRevision) + $extraWhereParams;
        unset($params[':created_at']);

        $attempts = 0;
        $lastError = null;
        while ($attempts < 3) {
            ++$attempts;
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->rowCount() > 0) {
                    if ($bumpFromRevision !== null) {
                        $run->revision = $bumpFromRevision + 1;
                    }

                    return true;
                }

                return false;
            } catch (Throwable $e) {
                $lastError = $e;
                if ($attempts < 3 && $this->isRetryable($e)) {
                    usleep(50_000 * $attempts);
                    continue;
                }
                break;
            }
        }

        throw new WorkflowRuntimeException(
            'Failed to CAS persist workflow run [' . $run->runId . ']: ' . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(WorkflowRun $run, string $payload, int $revision): array
    {
        return [
            ':run_id' => $run->runId,
            ':workflow_id' => $run->compiled->workflowId(),
            ':version' => $run->compiled->version(),
            ':status' => $run->status->value,
            ':revision' => $revision,
            ':pause_node_id' => $run->pauseNodeId,
            ':assignee' => $this->resolveAssignee($run),
            ':payload' => $payload,
            ':created_at' => $run->createdAt,
            ':updated_at' => $run->updatedAt,
        ];
    }

    /** {@inheritdoc} */
    public function find(string $runId): ?WorkflowRun
    {
        try {
            $stmt = $this->connection()->prepare(
                "SELECT payload FROM {$this->table} WHERE run_id = :run_id AND deleted_at IS NULL LIMIT 1",
            );
            $stmt->execute([':run_id' => $runId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new WorkflowRuntimeException("Failed to load workflow run [{$runId}]: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($row) || !isset($row['payload']) || !is_string($row['payload']) || $row['payload'] === '') {
            return null;
        }

        try {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new WorkflowRuntimeException("Corrupt workflow run payload [{$runId}]", 0, $e);
        }

        if (!is_array($payload)) {
            return null;
        }

        return WorkflowRunSnapshot::fromArray($payload)->hydrate($this->registry);
    }

    /** {@inheritdoc} */
    public function listWaiting(?string $assignee = null): array
    {
        $pdo = $this->connection();
        if ($assignee !== null && $assignee !== '') {
            $stmt = $pdo->prepare(
                "SELECT payload FROM {$this->table}
                 WHERE status = :status AND assignee = :assignee AND deleted_at IS NULL
                 ORDER BY updated_at ASC",
            );
            $stmt->execute([
                ':status' => RunStatus::WAITING->value,
                ':assignee' => $assignee,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT payload FROM {$this->table}
                 WHERE status = :status AND deleted_at IS NULL
                 ORDER BY updated_at ASC",
            );
            $stmt->execute([':status' => RunStatus::WAITING->value]);
        }

        $runs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row) || !isset($row['payload']) || !is_string($row['payload'])) {
                continue;
            }
            try {
                $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (!is_array($payload)) {
                continue;
            }
            try {
                $runs[] = WorkflowRunSnapshot::fromArray($payload)->hydrate($this->registry);
            } catch (Throwable) {
                // 定义已卸载等：跳过坏数据，避免整表查询失败
                continue;
            }
        }

        return $runs;
    }

    private function resolveAssignee(WorkflowRun $run): ?string
    {
        if ($run->pauseNodeId === null || $run->pauseNodeId === '') {
            return null;
        }

        $output = $run->state->outputOf($run->pauseNodeId) ?? [];
        $assignee = is_array($output) ? ($output['assignee'] ?? null) : null;

        return is_string($assignee) && $assignee !== '' ? $assignee : null;
    }

    /**
     * 每次 IO 取 PDO：Closure resolver 走当前协程 Application，禁止冻死首次 cid。
     */
    private function connection(): PDO
    {
        $pdo = $this->pdo;
        if ($pdo instanceof \Closure) {
            $resolved = $pdo();
            if (!$resolved instanceof PDO) {
                throw new WorkflowException('DbRunStore resolver must return ' . PDO::class);
            }
            $resolved->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $resolved;
        }

        return $pdo;
    }

    private function upsertSql(string $driver): string
    {
        $table = $this->table;
        $columns = 'run_id, workflow_id, version, status, revision, pause_node_id, assignee, payload, created_at, updated_at, deleted_at';
        $values = ':run_id, :workflow_id, :version, :status, :revision, :pause_node_id, :assignee, :payload, :created_at, :updated_at, NULL';

        if ($driver === 'sqlite') {
            return "INSERT INTO {$table} ({$columns}) VALUES ({$values})
                ON CONFLICT(run_id) DO UPDATE SET
                    workflow_id = excluded.workflow_id,
                    version = excluded.version,
                    status = excluded.status,
                    revision = excluded.revision,
                    pause_node_id = excluded.pause_node_id,
                    assignee = excluded.assignee,
                    payload = excluded.payload,
                    updated_at = excluded.updated_at,
                    deleted_at = NULL";
        }

        return "INSERT INTO {$table} ({$columns}) VALUES ({$values})
            ON DUPLICATE KEY UPDATE
                workflow_id = VALUES(workflow_id),
                version = VALUES(version),
                status = VALUES(status),
                revision = VALUES(revision),
                pause_node_id = VALUES(pause_node_id),
                assignee = VALUES(assignee),
                payload = VALUES(payload),
                updated_at = VALUES(updated_at),
                deleted_at = NULL";
    }

    private function updateSql(string $driver, string $whereExtra): string
    {
        $table = $this->table;
        $set = 'workflow_id = :workflow_id,
                version = :version,
                status = :status,
                revision = :revision,
                pause_node_id = :pause_node_id,
                assignee = :assignee,
                payload = :payload,
                updated_at = :updated_at,
                deleted_at = NULL';
        $where = "run_id = :run_id {$whereExtra} AND deleted_at IS NULL";

        if ($driver === 'sqlite') {
            return "UPDATE {$table} SET {$set} WHERE {$where}";
        }

        return "UPDATE `{$table}` SET {$set} WHERE {$where}";
    }

    private function isRetryable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $message = strtolower($e->getMessage());
        foreach (['deadlock', 'lock wait timeout', 'try restarting transaction', 'database is locked'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        $code = (string) $e->getCode();

        return in_array($code, ['40001', '1213', '1205'], true);
    }
}
