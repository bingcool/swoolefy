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

namespace Swoolefy\Support\Claim;

use PDO;
use Swoolefy\Library\Db\BaseQuery;
use Swoolefy\Library\Db\Facade\Db;
use Throwable;

/**
 * 数据库行级认领队列。对应 gofy claim.Queue。
 *
 * 每个副本跑同一个扫描循环：SELECT ... FOR UPDATE SKIP LOCKED 跳过别人正在处理的行，
 * 认领与UPDATE在同一个事务里，崩溃不会留下「认领了但没人负责」的行。
 * 收尾（succeed/fail）用 owner + status + attempts 三重围栏，挡住过期 handler 的写回；
 * 每行执行前用 {@see extend()} 重新武装租约，避免队列里等待过久的行被提前回收。
 *
 * rerun_allowed = 0 的行只执行一次：handler 报错或租约过期都直接转 dead，不再重试。
 * 表由业务维护：本类只提供 {@see ddl()} 生成的建表语句，不做迁移。
 */
final class ClaimQueue
{
    public const STATUS_PENDING = 0;

    public const STATUS_CLAIMED = 1;

    public const STATUS_DONE = 2;

    public const STATUS_DEAD = 3;

    /**
     * Reap 把行置为 dead 时写入的失败原因。
     *
     * 只写「租约过期」分不清是任务真失败，还是根本没拿到第二次机会，而这两种情况
     * 的人工处理方式不同。
     */
    public const REASON_ATTEMPTS_EXHAUSTED = 'attempts exhausted after the lease expired';

    public const REASON_NO_RERUN = 'lease expired and rerun_allowed = 0';

    private ClaimConfig $config;

    private string $table;

    private string $selectSql;

    private string $updateSql;

    private string $extendSql;

    private string $ownsSql;

    private string $succeedSql;

    private string $failSql;

    private string $deadSql;

    private string $deadNoRerunSql;

    private string $reapSql;

    private string $reapDeadSql;

    private string $reapNoRerunSql;

    /**
     * @param BaseQuery $query Db::connect('db') 得到的查询对象；事务与 SQL 都走这一条协程连接
     */
    public function __construct(
        private readonly BaseQuery $query,
        ClaimConfig $config,
    ) {
        $config = $config->withDefaults();
        if ($config->batch > ClaimConfig::MAX_BATCH) {
            throw ClaimException::batchTooLarge($config->batch);
        }
        if ($config->job === '') {
            throw ClaimException::jobRequired();
        }
        $this->table = self::quoteTable($config->table);
        $this->config = $config;

        // claim 表不带 tenant_id；显式关掉租户拦截器，避免它改写这里的原生 SQL。
        $this->query->setOption('without_tenant_scope', true);

        $table = $this->table;
        $this->selectSql = <<<SQL
SELECT id, job_type, payload, attempts
  FROM {$table}
 WHERE job_type = ? AND status = 0
   AND (next_run_at IS NULL OR next_run_at <= NOW(3))
 ORDER BY next_run_at, id
 LIMIT ?
 FOR UPDATE SKIP LOCKED
SQL;

        // 时间列是秒精度 DATETIME。延迟在 PHP 侧向上取整成整秒，避免 MySQL 把
        // 亚秒值舍入成 0 而立刻重试。过期比较仍用 NOW(3)，两种精度都能正确比较。
        $this->updateSql = <<<SQL
UPDATE {$table}
   SET status = 1, owner = ?, attempts = attempts + 1,
       start_handle_time = CURRENT_TIMESTAMP,
       lease_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)
 WHERE id IN (?)
SQL;

        // Extend 在每条任务执行前重新武装它的租约。Claim 给整批行写同一个 lease_until，
        // 但 Runner 串行执行：靠后的行可能还没轮到就已过期。从执行时刻重新计时给它完整的
        // 租约窗口；owner/status/attempts 围栏让已被其它副本回收的行 CAS 失败——于是过期
        // handler 在产生副作用之前就被跳过。
        $this->extendSql = <<<SQL
UPDATE {$table}
   SET lease_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)
 WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?
SQL;
        // MySQL 默认返回被修改的行数而非匹配行数（除非设了 CLIENT_FOUND_ROWS，不能假设）。
        // 同一秒内续租可能算出相同的 lease_until，导致 affected=0。ownsSql 让 extend() 能
        // 区分「空更新」与「租约已丢」：重读围栏字段，检查是否仍成立。
        $this->ownsSql = <<<SQL
SELECT owner, status, attempts FROM {$table} WHERE id = ?
SQL;

        $guard = 'WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?';
        // Succeed 清掉上一次的失败原因：否则已完成的行还带着旧 reason，查表的人会以为它失败。
        $this->succeedSql = <<<SQL
UPDATE {$table}
   SET status = 2, owner = '', lease_until = NULL, fail_reason = NULL,
       finish_handle_time = CURRENT_TIMESTAMP
 {$guard}
SQL;
        $this->failSql = <<<SQL
UPDATE {$table}
   SET status = 0, owner = '', lease_until = NULL, fail_reason = ?,
       fail_handle_time = CURRENT_TIMESTAMP,
       next_run_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)
 {$guard} AND rerun_allowed = 1
SQL;
        $this->deadSql = <<<SQL
UPDATE {$table}
   SET status = 3, owner = '', lease_until = NULL, fail_reason = ?,
       fail_handle_time = CURRENT_TIMESTAMP
 {$guard}
SQL;
        // rerun_allowed = 0 的行只允许执行一次。handler 报错也算执行过一次，因此置为 dead
        // 而不是重新排期。该语句在 failSql 未命中后执行：行仍属于我们，只是拒绝再跑。
        $this->deadNoRerunSql = <<<SQL
UPDATE {$table}
   SET status = 3, owner = '', lease_until = NULL, fail_reason = ?,
       fail_handle_time = CURRENT_TIMESTAMP
 {$guard} AND rerun_allowed = 0
SQL;

        // 每个副本都可以 Reap：UPDATE 只碰租约确实过期的行，并发调用是幂等的。
        $this->reapSql = <<<SQL
UPDATE {$table}
   SET status = 0, owner = '', lease_until = NULL
 WHERE job_type = ? AND status = 1 AND lease_until IS NOT NULL AND lease_until < NOW(3)
   AND attempts < ? AND rerun_allowed = 1
SQL;
        $this->reapDeadSql = <<<SQL
UPDATE {$table}
   SET status = 3, owner = '', lease_until = NULL, fail_reason = ?,
       fail_handle_time = CURRENT_TIMESTAMP
 WHERE job_type = ? AND status = 1 AND lease_until IS NOT NULL AND lease_until < NOW(3)
   AND attempts >= ? AND rerun_allowed = 1
SQL;
        // 过期租约的另一半：拒绝重跑的行不能回到待认领，租约一过期就置为 dead。两条回收
        // 语句靠 rerun_allowed 互斥；这一条不判断 attempts——对不会重跑的行来说上限没有意义。
        $this->reapNoRerunSql = <<<SQL
UPDATE {$table}
   SET status = 3, owner = '', lease_until = NULL, fail_reason = ?,
       fail_handle_time = CURRENT_TIMESTAMP
 WHERE job_type = ? AND status = 1 AND lease_until IS NOT NULL AND lease_until < NOW(3)
   AND rerun_allowed = 0
SQL;
    }

    /**
     * 用应用里的 DB 组件构造队列。$component 是 Config/component/db.php 里的别名，通常是 db。
     */
    public static function fromComponent(string $component, ClaimConfig $config): self
    {
        if ($component === '') {
            throw ClaimException::dbRequired();
        }

        return new self(Db::connect($component), $config);
    }

    public function job(): string
    {
        return $this->config->job;
    }

    public function batch(): int
    {
        return $this->config->batch;
    }

    public function leaseSeconds(): int
    {
        return $this->config->leaseSeconds;
    }

    public function maxAttempts(): int
    {
        return $this->config->maxAttempts;
    }

    /**
     * 为 $owner 锁定并标记最多 $limit 行。没有到期任务时返回空数组，调用方应退避而不是空转。
     * $limit <= 0 表示用配置的 batch，> {@see ClaimConfig::MAX_BATCH} 会拒绝。
     *
     * @return list<ClaimTask>
     */
    public function claim(string $owner, int $limit = 0): array
    {
        $owner = trim($owner);
        if ($owner === '') {
            throw ClaimException::ownerRequired();
        }
        $limit = $limit > 0 ? $limit : $this->config->batch;
        if ($limit > ClaimConfig::MAX_BATCH) {
            // 显式传的 limit 来自调用方而不是已验证的配置，因此这里也要校验：
            // 不管数字从哪来，MAX_BATCH 都是上限。
            throw ClaimException::batchTooLarge($limit, 'requested');
        }

        $inTransaction = false;
        try {
            $this->query->beginTransaction();
            $inTransaction = true;

            $rows = $this->query->query($this->selectSql, [
                $this->config->job,
                [$limit, PDO::PARAM_INT],
            ]);
            if ($rows === []) {
                $this->query->commit();
                $inTransaction = false;
                $this->config->hooks?->claim($this->config->job, 0);

                return [];
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = [(int) $row['id'], PDO::PARAM_INT];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge(
                [$owner, [$this->config->leaseSeconds, PDO::PARAM_INT]],
                $ids,
            );
            $this->query->execute(str_replace('IN (?)', 'IN (' . $placeholders . ')', $this->updateSql), $params);

            $this->query->commit();
            $inTransaction = false;
        } catch (Throwable $e) {
            if ($inTransaction) {
                $this->query->rollback();
            }
            throw $e instanceof ClaimException ? $e : ClaimException::dbError('claim', $e);
        }

        $tasks = [];
        foreach ($rows as $row) {
            $tasks[] = new ClaimTask(
                id: (int) $row['id'],
                attempts: (int) $row['attempts'] + 1,
                payload: isset($row['payload']) ? (string) $row['payload'] : null,
                job: $this->config->job,
                owner: $owner,
            );
        }
        $this->config->hooks?->claim($this->config->job, count($tasks));

        return $tasks;
    }

    /**
     * 在 handler 开始前把一条已认领行的租约重新武装为一个完整的租约窗口。
     *
     * Runner 对批次中每条任务都调用它，因为 claim() 给所有行写了同一个截止时间；
     * 否则排在慢任务后面的行可能被回收、被另一个副本执行。owner + status + attempts
     * 的 CAS 保证已被回收的行抛 LEASE_LOST，调用方必须跳过 handler——它的副作用
     * 绝不能跑两遍。
     *
     * @throws ClaimException 围栏不匹配时抛 LEASE_LOST
     */
    public function extend(ClaimTask $task): void
    {
        $owner = $this->fence($task);

        try {
            $affected = $this->query->execute($this->extendSql, [
                [$this->config->leaseSeconds, PDO::PARAM_INT],
                $task->id,
                $owner,
                [$task->attempts, PDO::PARAM_INT],
            ]);
            if ($affected > 0) {
                return;
            }

            // 0 行被修改可能是「已被接管」，也可能是「新 lease_until 与旧值相同」
            // （DATETIME 秒精度、同一秒内续租）。重读围栏：只有 owner/status/attempts
            // 都匹配才算仍持有，空更新无害。
            $rows = $this->query->query($this->ownsSql, [$task->id]);
        } catch (Throwable $e) {
            throw ClaimException::dbError('extend', $e);
        }

        $row = $rows[0] ?? null;
        if ($row !== null
            && (string) ($row['owner'] ?? '') === $owner
            && (int) ($row['status'] ?? -1) === self::STATUS_CLAIMED
            && (int) ($row['attempts'] ?? -1) === $task->attempts) {
            return;
        }

        throw ClaimException::leaseLost($task->id);
    }

    /**
     * 结束任务：写 finish_handle_time 并清掉 fail_reason。
     *
     * @throws ClaimException 围栏不匹配时抛 LEASE_LOST，此时业务不得重试副作用
     */
    public function succeed(ClaimTask $task): void
    {
        $owner = $this->fence($task);
        $affected = $this->execute('succeed', $this->succeedSql, [
            $task->id,
            $owner,
            [$task->attempts, PDO::PARAM_INT],
        ]);
        if ($affected === 0) {
            $this->config->hooks?->leaseLost($this->config->job);
            throw ClaimException::leaseLost($task->id);
        }
    }

    /**
     * 失败一次：记录原因与时间，安排指数退避重试；次数用尽或行拒绝重跑则转为 dead。
     *
     * @param Throwable|string|null $cause 失败原因。Throwable 取 message；null 写空串
     * @return bool 行是否被置为 dead。次数用尽或 rerun_allowed=0 时为 true，表示不会再重试；
     *         调用方据此区分「放弃了」与「还会再试」，不需要再查一次库
     * @throws ClaimException 围栏不匹配时抛 LEASE_LOST
     */
    public function fail(ClaimTask $task, Throwable|string|null $cause = null): bool
    {
        $owner = $this->fence($task);
        $reason = self::failureReason($cause);

        // attempts 在认领时已自增，因此这里达到上限意味着本次失败就是最后一次尝试。
        if ($task->attempts >= $this->config->maxAttempts) {
            $affected = $this->execute('dead', $this->deadSql, [
                $reason,
                $task->id,
                $owner,
                [$task->attempts, PDO::PARAM_INT],
            ]);
            if ($affected === 0) {
                $this->config->hooks?->leaseLost($this->config->job);
                throw ClaimException::leaseLost($task->id);
            }

            return true;
        }

        $delay = ClaimConfig::backoffSeconds(
            $this->config->backoffSeconds,
            $this->config->maxBackoffSeconds,
            $task->attempts,
        );
        $affected = $this->execute('fail', $this->failSql, [
            $reason,
            [$delay, PDO::PARAM_INT],
            $task->id,
            $owner,
            [$task->attempts, PDO::PARAM_INT],
        ]);
        if ($affected > 0) {
            return false;
        }

        // failSql 未命中只有两种可能，而它们要用相反的方式处理：行拒绝再跑
        // （rerun_allowed = 0），或行已不属于我们。前者置死，后者报 LEASE_LOST。
        // 该语句带同样的 owner/status/attempts 围栏，因此只有行仍属于我们时才会命中。
        $affected = $this->execute('dead (no rerun)', $this->deadNoRerunSql, [
            $reason,
            $task->id,
            $owner,
            [$task->attempts, PDO::PARAM_INT],
        ]);
        if ($affected > 0) {
            return true;
        }

        $this->config->hooks?->leaseLost($this->config->job);
        throw ClaimException::leaseLost($task->id);
    }

    /**
     * 回收过期租约：未耗尽次数且允许重跑的行回到 pending，其余转 dead。
     *
     * Reap 不是业务失败；但置为 dead 时会写入可区分的原因（次数耗尽 / rerun_allowed = 0），
     * 打开表就能判断是任务真失败，还是根本没拿到第二次机会。
     *
     * @return array{0: int, 1: int} [reaped, dead]
     */
    public function reap(): array
    {
        $reaped = $this->execute('reap', $this->reapSql, [
            $this->config->job,
            [$this->config->maxAttempts, PDO::PARAM_INT],
        ]);
        $dead = $this->execute('reap dead', $this->reapDeadSql, [
            self::REASON_ATTEMPTS_EXHAUSTED,
            $this->config->job,
            [$this->config->maxAttempts, PDO::PARAM_INT],
        ]);
        $dead += $this->execute('reap no-rerun', $this->reapNoRerunSql, [
            self::REASON_NO_RERUN,
            $this->config->job,
        ]);
        $this->config->hooks?->reap($this->config->job, $reaped, $dead);

        return [$reaped, $dead];
    }

    /**
     * 返回查询所依赖的建表语句，包含防止 SKIP LOCKED 退化为全表扫描的索引。
     *
     * rerun_allowed 是业务侧的逐行开关，在 at-least-once 与 at-most-once 之间选择。
     * 默认 1：失败或被放弃的行可以被再次认领。对绝不能执行两次的活设为 0——claim 就
     * 不再重新排期它：handler 报错直接置 dead 而不是退避重试，租约过期直接置 dead
     * 而不是放回待认领。仍在待认领状态的行还是可能被认领一次，因此要在插入时就设好
     * 这一列，而不是等 handler 跑起来再改。claim 只读这一列，从不写它。
     */
    public static function ddl(string $table): string
    {
        $quoted = self::quoteTable(trim($table));

        return <<<SQL
CREATE TABLE IF NOT EXISTS {$quoted} (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  job_type    VARCHAR(64)  NOT NULL COMMENT '任务类型',
  status      TINYINT      NOT NULL DEFAULT 0 COMMENT '任务状态：0待认领(待处理) 1已认领(进行中) 2已完成 3处理失败,待人工介入',
  owner       VARCHAR(96)  NOT NULL DEFAULT '' COMMENT '当前认领者',
  lease_until DATETIME     NULL COMMENT '租约到期时间',
  attempts    INT          NOT NULL DEFAULT 0 COMMENT '已认领次数',
  next_run_at DATETIME     NULL COMMENT '下次可执行时间，NULL表示立即可认领',
  rerun_allowed TINYINT    NOT NULL DEFAULT 1 COMMENT '是否允许被再次消费：1允许(失败重试/租约过期后可被重跑)，0只执行一次(失败或租约过期直接转3)',
  payload     JSON         NULL COMMENT '任务载荷',
  fail_reason TEXT         NULL DEFAULT NULL COMMENT '失败原因',
  start_handle_time  DATETIME NULL DEFAULT NULL COMMENT '状态改为1(已认领进行中)的时间',
  finish_handle_time DATETIME NULL DEFAULT NULL COMMENT '状态改为2(已完成)的时间',
  fail_handle_time   DATETIME NULL DEFAULT NULL COMMENT '最近一次失败时间',
  created_by  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '创建人',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  deleted_at  DATETIME     NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (id),
  KEY idx_claim (job_type, status, next_run_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='认领任务表'
SQL;
    }

    /**
     * 表名只允许普通标识符，最多带一层库名；只有表名会拼进 SQL，所有值都走绑定。
     */
    public static function quoteTable(string $table): string
    {
        $parts = explode('.', trim($table));
        if (count($parts) > 2) {
            throw ClaimException::invalidTable($table);
        }
        foreach ($parts as &$part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw ClaimException::invalidTable($table);
            }
            $part = '`' . $part . '`';
        }
        unset($part);

        return implode('.', $parts);
    }

    /**
     * 执行写语句并统一包装 DB 异常；LEASE_LOST 由调用方在围栏判断里抛。
     */
    private function execute(string $stage, string $sql, array $params): int
    {
        try {
            return $this->query->execute($sql, $params);
        } catch (Throwable $e) {
            throw ClaimException::dbError($stage, $e);
        }
    }

    /**
     * 校验围栏并取出 owner。attempts 为 0 的手工 task 无法证明自己还持有该行。
     */
    private function fence(ClaimTask $task): string
    {
        $owner = trim($task->owner);
        if ($owner === '') {
            throw ClaimException::ownerRequired();
        }
        if ($task->attempts < 1) {
            throw ClaimException::invalidTask($task->id);
        }

        return $owner;
    }

    /**
     * 失败原因限制在 2048 个字符，避免每条失败行都存一整段堆栈。
     */
    private static function failureReason(Throwable|string|null $cause): string
    {
        $message = match (true) {
            $cause instanceof Throwable => $cause->getMessage(),
            is_string($cause) => $cause,
            default => '',
        };

        return mb_substr($message, 0, 2048);
    }
}
