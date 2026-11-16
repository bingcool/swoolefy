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
 * claim 模块的纯逻辑单测：不连数据库，只校验配置、SQL 形状与围栏校验。
 */
final class ClaimQueueTest extends TestCase
{
    public function testConfigDefaultsAndBackoff(): void
    {
        $config = (new ClaimConfig(table: 'job_task', job: 'order'))->withDefaults();
        $this->assertSame(100, $config->batch);
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
    }

    public function testSqlUsesSkipLockedOwnerStatusAttemptsFenceAndTimeStamps(): void
    {
        $queue = $this->queue(new ClaimConfig(table: 'app.job_task', job: 'order'));
        $select = $this->sql($queue, 'selectSql');
        $succeed = $this->sql($queue, 'succeedSql');
        $fail = $this->sql($queue, 'failSql');
        $dead = $this->sql($queue, 'deadSql');

        $this->assertStringContainsString('`app`.`job_task`', $select);
        $this->assertStringContainsString('FOR UPDATE SKIP LOCKED', $select);
        foreach ([$succeed, $fail, $dead] as $sql) {
            $this->assertStringContainsString('WHERE id = ? AND owner = ? AND status = 1 AND attempts = ?', $sql);
        }
        $this->assertStringContainsString('start_handle_time = CURRENT_TIMESTAMP', $this->sql($queue, 'updateSql'));
        $this->assertStringContainsString('finish_handle_time = CURRENT_TIMESTAMP', $succeed);
        $this->assertStringContainsString('fail_handle_time = CURRENT_TIMESTAMP', $fail);
        $this->assertStringContainsString('fail_handle_time = CURRENT_TIMESTAMP', $dead);
        $this->assertStringContainsString('lease_until < NOW(3)', $this->sql($queue, 'reapSql'));
        $this->assertStringContainsString('attempts < ?', $this->sql($queue, 'reapSql'));
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

    private function queue(ClaimConfig $config): ClaimQueue
    {
        return new ClaimQueue($this->query(), $config);
    }

    private function query(): Query
    {
        $connection = new class implements ConnectionInterface {
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
                return [];
            }

            public function execute(string $sql, array $bindParams = []): int
            {
                return 0;
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
        };

        return new Query($connection);
    }

    private function sql(ClaimQueue $queue, string $property): string
    {
        $reflection = new \ReflectionProperty(ClaimQueue::class, $property);

        return (string) $reflection->getValue($queue);
    }
}
