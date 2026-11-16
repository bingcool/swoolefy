<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Claim;

use PHPUintTest\TestCase;
use Swoolefy\Library\Db\ConnectionInterface;
use Swoolefy\Library\Db\Query;
use Swoolefy\Support\Claim\ClaimConfig;
use Swoolefy\Support\Claim\ClaimException;
use Swoolefy\Support\Claim\ClaimQueue;
use Swoolefy\Support\Claim\ClaimRunner;
use Swoolefy\Support\Claim\ClaimTask;
use Swoolefy\Support\Claim\RunnerConfig;

/**
 * claim 模块的纯逻辑单测：不连数据库，只校验配置、SQL 形状、围栏校验与分支返回值。
 */
final class ClaimQueueTest extends TestCase
{
    public function testConfigDefaultsAndBackoff(): void
    {
        $config = (new ClaimConfig(table: 'job_task', job: 'order'))->withDefaults();
        $this->assertSame(10, $config->batch);
        $this->assertSame(ClaimConfig::MAX_BATCH, 20);
        $this->assertSame(60, $config->leaseSeconds);
        $this->assertSame(5, $config->maxAttempts);
        $this->assertSame(10, $config->backoffSeconds);
        $this->assertSame(600, $config->maxBackoffSeconds);

        $this->assertSame(10, ClaimConfig::backoffSeconds(10, 40, 1));
        $this->assertSame(20, ClaimConfig::backoffSeconds(10, 40, 2));
        $this->assertSame(40, ClaimConfig::backoffSeconds(10, 40, 3));
        $this->assertSame(40, ClaimConfig::backoffSeconds(10, 40, 99));
        $this->assertSame(5, ClaimConfig::backoffSeconds(10, 5, 1));
    }

    public function testQuoteTableRejectsAnythingButIdentifiers(): void
    {
        $this->assertSame('`app`.`job_task`', ClaimQueue::quoteTable('app.job_task'));
        foreach (['', 'bad table', 'a.b.c', '1jobs', 'jobs-archive', '`jobs`'] as $bad) {
            try {
                ClaimQueue::quoteTable($bad);
                $this->fail('bad table was accepted: ' . $bad);
            } catch (ClaimException $e) {
                $this->assertSame(ClaimException::INVALID_TABLE, $e->getCode());
            }
        }
    }

    public function testDdlMatchesShippedSql(): void
    {
        $path = dirname(__DIR__, 4) . '/src/Support/Claim/claim.sql';
        $body = trim(preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path)));
        $body = rtrim(trim($body), ';');
        $this->assertSame($body, ClaimQueue::ddl('claim_job_task'));
        $this->assertStringContainsString('rerun_allowed TINYINT    NOT NULL DEFAULT 1', ClaimQueue::ddl('claim_job_task'));
    }

    public function testSqlUsesSkipLockedOwnerStatusAttemptsFenceAndTimeStamps(): void
    {
        $queue = $this->queue(new ClaimConfig(table: 'app.job_task', job: 'order'));
        $select = $this->sql($queue, 'selectSql');
        $extend = $this->sql($queue, 'extendSql');
        $owns = $this->sql($queue, 'ownsSql');
        $succeed = $this->sql($queue, 'succeedSql');
        $fail = $this->sql($queue, 'failSql');
        $deadNoRerun = $this->sql($queue, 'deadNoRerunSql');

        $this->assertStringContainsString('`app`.`job_task`', $select);
        $this->assertStringContainsString('FOR UPDATE SKIP LOCKED', $select);
        foreach ([$succeed, $fail, $this->sql($queue, 'deadSql')] as $sql) {
            $this->assertStringContainsString('WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?', $sql);
        }
        $this->assertStringContainsString('start_handle_time = CURRENT_TIMESTAMP', $this->sql($queue, 'updateSql'));
        $this->assertStringContainsString('finish_handle_time = CURRENT_TIMESTAMP', $succeed);
        $this->assertStringContainsString('fail_handle_time = CURRENT_TIMESTAMP', $fail);
        $this->assertStringContainsString('fail_handle_time = CURRENT_TIMESTAMP', $this->sql($queue, 'deadSql'));

        // Extend 用同一套围栏续租；0 行时靠 owns 重读区分「同秒空更新」与「租约已丢」
        $this->assertStringContainsString('SET lease_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)', $extend);
        $this->assertStringContainsString('WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?', $extend);
        $this->assertStringContainsString('SELECT owner, status, attempts', $owns);

        // rerun_allowed 把失败与回收分成互斥的两条路径
        $this->assertStringContainsString('AND rerun_allowed = 1', $fail);
        $this->assertStringContainsString('AND rerun_allowed = 0', $deadNoRerun);
        $this->assertStringContainsString('AND rerun_allowed = 1', $this->sql($queue, 'reapSql'));
        $this->assertStringContainsString('AND rerun_allowed = 1', $this->sql($queue, 'reapDeadSql'));
        $this->assertStringContainsString('AND rerun_allowed = 0', $this->sql($queue, 'reapNoRerunSql'));
        $this->assertStringContainsString('lease_until < NOW(3)', $this->sql($queue, 'reapSql'));
        $this->assertStringContainsString('attempts < ?', $this->sql($queue, 'reapSql'));
    }

    public function testBatchIsCappedAtMax(): void
    {
        try {
            new ClaimQueue($this->query(), new ClaimConfig(
                table: 'job_task',
                job: 'order',
                batch: ClaimConfig::MAX_BATCH + 1,
            ));
            $this->fail('oversized config batch was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::BATCH_TOO_LARGE, $e->getCode());
        }

        $queue = $this->queue(new ClaimConfig(table: 'job_task', job: 'order'));
        try {
            $queue->claim('worker-a', ClaimConfig::MAX_BATCH + 1);
            $this->fail('oversized claim limit was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::BATCH_TOO_LARGE, $e->getCode());
        }
    }

    public function testClaimRequiresOwner(): void
    {
        try {
            $this->queue(new ClaimConfig(table: 'job_task', job: 'order'))->claim('  ');
            $this->fail('blank owner was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::OWNER_REQUIRED, $e->getCode());
        }
    }

    public function testCompletionRequiresFencingPair(): void
    {
        $queue = $this->queue(new ClaimConfig(table: 'job_task', job: 'order'));
        try {
            $queue->succeed(new ClaimTask(id: 7, attempts: 0, owner: 'worker-a'));
            $this->fail('task without attempts was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::INVALID_TASK, $e->getCode());
        }

        try {
            $queue->fail(new ClaimTask(id: 7, attempts: 1, owner: ' '), 'boom');
            $this->fail('task without owner was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::OWNER_REQUIRED, $e->getCode());
        }
    }

    public function testFailDistinguishesRetryParkAndLeaseLost(): void
    {
        $connection = new ClaimFakeConnection();
        $queue = new ClaimQueue(new Query($connection), new ClaimConfig(
            table: 'job_task',
            job: 'order',
            maxAttempts: 2,
        ));

        // 次数未耗尽且 fail 命中 → 只退避，不置死
        $connection->executeResults = [1];
        $this->assertFalse($queue->fail(new ClaimTask(id: 1, attempts: 1, owner: 'worker-a'), 'boom'));

        // fail 未命中、deadNoRerun 命中 → rerun_allowed = 0，直接置死
        $connection->executeResults = [0, 1];
        $this->assertTrue($queue->fail(new ClaimTask(id: 2, attempts: 1, owner: 'worker-a'), 'boom'));

        // 两条都没命中 → 行已被接管
        $connection->executeResults = [0, 0];
        try {
            $queue->fail(new ClaimTask(id: 3, attempts: 1, owner: 'worker-a'), 'boom');
            $this->fail('stale fail was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::LEASE_LOST, $e->getCode());
        }

        // 次数用尽直接走 deadSQL，并返回 true
        $connection->executeResults = [1];
        $this->assertTrue($queue->fail(new ClaimTask(id: 4, attempts: 2, owner: 'worker-a'), 'boom'));
    }

    public function testExtendFallsBackToOwnershipReadWhenUpdateChangesNothing(): void
    {
        $connection = new ClaimFakeConnection();
        $queue = new ClaimQueue(new Query($connection), new ClaimConfig(table: 'job_task', job: 'order'));
        $task = new ClaimTask(id: 9, attempts: 2, owner: 'worker-a');

        // 更新命中 → 直接成功，不重读
        $connection->executeResults = [1];
        $queue->extend($task);
        $this->assertCount(0, $connection->queriedSql);

        // 更新 0 行（同秒续租算出相同 lease_until），重读围栏仍匹配 → 正常返回
        $connection->executeResults = [0];
        $connection->queryResults = [[['owner' => 'worker-a', 'status' => 1, 'attempts' => 2]]];
        $queue->extend($task);
        $this->assertCount(1, $connection->queriedSql);

        // 更新 0 行，重读发现被接管 → LEASE_LOST
        $connection->executeResults = [0];
        $connection->queryResults = [[['owner' => 'worker-b', 'status' => 1, 'attempts' => 2]]];
        try {
            $queue->extend($task);
            $this->fail('stale extend was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::LEASE_LOST, $e->getCode());
        }
    }

    public function testReapSumsDeadFromBothBranches(): void
    {
        $connection = new ClaimFakeConnection();
        $queue = new ClaimQueue(new Query($connection), new ClaimConfig(table: 'job_task', job: 'order'));

        $connection->executeResults = [3, 1, 2];
        $this->assertSame([3, 3], $queue->reap());
        $this->assertStringContainsString('rerun_allowed = 1', $connection->executedSql[0]);
        $this->assertStringContainsString('rerun_allowed = 1', $connection->executedSql[1]);
        $this->assertStringContainsString('rerun_allowed = 0', $connection->executedSql[2]);
    }

    public function testRunnerRequiresHandlerAndAppliesDefaults(): void
    {
        $config = new ClaimConfig(table: 'job_task', job: 'order');
        try {
            new ClaimRunner($this->query(), new RunnerConfig($config));
            $this->fail('runner without handler was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::HANDLER_REQUIRED, $e->getCode());
        }

        $runner = new ClaimRunner($this->query(), new RunnerConfig(
            $config,
            static fn (?string $data): int => 1,
        ));
        $this->assertSame(ClaimRunner::DEFAULT_HANDLER_TIMEOUT_SECONDS, $runner->handlerTimeoutSeconds());
        $this->assertSame('order', $runner->queue()->job());
        $this->assertSame(5, $runner->queue()->maxAttempts());
    }

    public function testRunnerRejectsHandlerTimeoutNotShorterThanLease(): void
    {
        try {
            new ClaimRunner($this->query(), new RunnerConfig(
                new ClaimConfig(table: 'job_task', job: 'order', leaseSeconds: 5),
                static fn (?string $data): int => 1,
                handlerTimeoutSeconds: 5.0,
            ));
            $this->fail('handler timeout equal to lease was accepted');
        } catch (ClaimException $e) {
            $this->assertSame(ClaimException::HANDLER_TIMEOUT_EXCEEDS_LEASE, $e->getCode());
        }
    }

    private function queue(ClaimConfig $config): ClaimQueue
    {
        return new ClaimQueue($this->query(), $config);
    }

    private function query(): Query
    {
        return new Query(new ClaimFakeConnection());
    }

    private function sql(ClaimQueue $queue, string $property): string
    {
        $reflection = new \ReflectionProperty(ClaimQueue::class, $property);

        return (string) $reflection->getValue($queue);
    }
}

/**
 * 可编排返回值的假连接：execute() 依次消费 $executeResults，query() 依次消费 $queryResults。
 */
final class ClaimFakeConnection implements ConnectionInterface
{
    /** @var list<int> */
    public array $executeResults = [];

    /** @var list<array<string, mixed>> */
    public array $queryResults = [];

    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<string> */
    public array $queriedSql = [];

    public function connect(array $config = [])
    {
        return $this;
    }

    public function getConfig(string $name = '')
    {
        return '';
    }

    public function close()
    {
        return $this;
    }

    public function query(string $sql, array $bindParams): array
    {
        $this->queriedSql[] = $sql;

        return array_shift($this->queryResults) ?? [];
    }

    public function execute(string $sql, array $bindParams = []): int
    {
        $this->executedSql[] = $sql;

        return array_shift($this->executeResults) ?? 0;
    }

    public function beginTransaction()
    {
    }

    public function commit()
    {
    }

    public function rollback()
    {
    }

    public function getLastSql(): string
    {
        return '';
    }

    /**
     * ClaimQueue 构造时关掉了租户拦截器，BaseQuery 会在每次 query/execute 前后调这两个方法。
     * 真实实现由 library 的 PDOConnection 提供，这里只需存在。
     */
    public function beginIgnoreTenantInterceptor(): void
    {
    }

    public function endIgnoreTenantInterceptor(): void
    {
    }
}
