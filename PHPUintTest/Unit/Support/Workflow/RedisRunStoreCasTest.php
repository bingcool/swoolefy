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

namespace PHPUintTest\Unit\Support\Workflow;

use ReflectionClass;
use Swoolefy\Library\Redis\Redis;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RedisRunStore;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use PHPUintTest\TestCase;
use Throwable;

/**
 * RedisRunStore Lua CAS（Compare-And-Swap）单测。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | saveIfStatus | 期望状态匹配时 CAS 成功；不匹配或 key 缺失时失败 |
 * | 并发认领 | 第二次 WAITING→RUNNING 认领应被拒绝，防止双 resume |
 * | WAITING TTL | WAITING 状态 key 不设过期；离开 WAITING 后恢复配置 TTL |
 *
 * 需要本机 Redis（127.0.0.1:6379）+ phpredis 扩展；不可用时相关用例自动跳过。
 */
#[\PHPUnit\Framework\Attributes\Group('redis')]
final class RedisRunStoreCasTest extends TestCase
{
    private function requireRedis(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not loaded');
        }

        $redis = new Redis();
        try {
            $redis->connect('127.0.0.1', 6379, 1.0);
            $redis->close();
        } catch (Throwable) {
            $this->markTestSkipped('redis unavailable');
        }
    }

    /**
     * @return array{0: RedisRunStore, 1: WorkflowRegistry}
     */
    private function makeRedisRunStore(): array
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 1.0);

        $registry = new WorkflowRegistry();
        $registry->register('cas', static fn () => WorkflowDefinition::create('cas', '1.0.0')
            ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())));

        $prefix = 'workflow:run:cas_test:' . bin2hex(random_bytes(4)) . ':';
        $store = new RedisRunStore($redis, $registry, $prefix, 3600);

        return [$store, $registry];
    }

    private function makeWaitingRun(WorkflowRegistry $registry, string $runId): WorkflowRun
    {
        $compiled = (new WorkflowCompiler())->compile($registry->definition('cas'));
        $now = WorkflowRunTime::now();

        return new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: RunStatus::WAITING,
            state: WorkflowState::fromInput([], []),
            createdAt: $now,
            updatedAt: $now,
            pauseNodeId: 'pause',
        );
    }

    /**
     * 验证：Redis 中 run 当前为 WAITING 时，saveIfStatus(..., WAITING) 应成功并写入 RUNNING。
     */
    public function testRedisCasSucceedsWhenStatusMatches(): void
    {
        $this->requireRedis();
        [$store, $registry] = $this->makeRedisRunStore();
        $run = $this->makeWaitingRun($registry, 'run_cas_ok');

        $store->save($run);
        $run->status = RunStatus::RUNNING;
        $run->pauseNodeId = null;
        $run->updatedAt = WorkflowRunTime::now();

        $this->assertTrue($store->saveIfStatus($run, RunStatus::WAITING), 'cas should succeed');
        $this->assertSame(RunStatus::RUNNING, $store->find('run_cas_ok')?->status, 'status updated');
    }

    /**
     * 验证：期望状态与实际 Redis 中状态不一致时，saveIfStatus 返回 false 且不修改存储。
     */
    public function testRedisCasFailsWhenStatusMismatch(): void
    {
        $this->requireRedis();
        [$store, $registry] = $this->makeRedisRunStore();
        $run = $this->makeWaitingRun($registry, 'run_cas_mismatch');

        $store->save($run);
        $run->status = RunStatus::RUNNING;
        $this->assertFalse($store->saveIfStatus($run, RunStatus::RUNNING), 'expected WAITING not RUNNING');
        $this->assertSame(RunStatus::WAITING, $store->find('run_cas_mismatch')?->status, 'unchanged');
    }

    /**
     * 验证：Redis 中不存在对应 run key 时，saveIfStatus 直接失败。
     */
    public function testRedisCasFailsWhenKeyMissing(): void
    {
        $this->requireRedis();
        [$store, $registry] = $this->makeRedisRunStore();
        $run = $this->makeWaitingRun($registry, 'run_cas_missing');

        $this->assertFalse($store->saveIfStatus($run, RunStatus::WAITING), 'missing key');
    }

    /**
     * 验证：两个 Worker 先后对同一 WAITING run 执行 CAS 认领时，仅第一次成功。
     */
    public function testRedisCasSecondClaimFails(): void
    {
        $this->requireRedis();
        [$store, $registry] = $this->makeRedisRunStore();
        $run = $this->makeWaitingRun($registry, 'run_cas_double');

        $store->save($run);

        $first = $store->find('run_cas_double');
        $this->assertNotNull($first, 'run loaded');
        $first->status = RunStatus::RUNNING;
        $first->pauseNodeId = null;
        $first->updatedAt = WorkflowRunTime::now();
        $this->assertTrue($store->saveIfStatus($first, RunStatus::WAITING), 'first claim');

        $second = $store->find('run_cas_double');
        $this->assertNotNull($second, 'run loaded again');
        $second->status = RunStatus::RUNNING;
        $second->pauseNodeId = null;
        $this->assertFalse($store->saveIfStatus($second, RunStatus::WAITING), 'second claim fails');
    }

    /**
     * 验证：WAITING 状态的 run key 在 Redis 中不应设置过期时间；转为 COMPLETED 后应用配置 TTL。
     */
    public function testRedisWaitingRunHasNoTtl(): void
    {
        $this->requireRedis();
        [$store, $registry] = $this->makeRedisRunStore();
        $run = $this->makeWaitingRun($registry, 'run_waiting_ttl');
        $store->save($run);

        $ref = new ReflectionClass($store);
        $prefixProp = $ref->getProperty('prefix');
        $prefixProp->setAccessible(true);
        $prefix = $prefixProp->getValue($store);
        $redisProp = $ref->getProperty('redis');
        $redisProp->setAccessible(true);
        /** @var Redis $redis */
        $redis = $redisProp->getValue($store);

        $key = $prefix . 'run_waiting_ttl';
        $ttl = (int) $redis->ttl($key);
        $this->assertLessThan(0, $ttl, 'WAITING key must not expire (ttl=-1 permanent or -2 missing)');
        $this->assertNotNull($store->find('run_waiting_ttl'), 'WAITING run still readable');

        $run->status = RunStatus::COMPLETED;
        $run->pauseNodeId = null;
        $run->updatedAt = WorkflowRunTime::now();
        $store->save($run);

        $ttlAfter = (int) $redis->ttl($key);
        $this->assertGreaterThan(0, $ttlAfter, 'non-WAITING run applies configured TTL');
    }
}
