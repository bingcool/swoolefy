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
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/RedisRunStoreCasTest.php
 * ```
 *
 * 需要本机 Redis（127.0.0.1:6379）+ phpredis 扩展；不可用时相关用例自动跳过。
 */

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

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 通用断言与输出
// ---------------------------------------------------------------------------

/** 断言条件为真，否则以给定消息抛出 RuntimeException 使单测失败。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 在 CLI 输出 [PASS] 标记，便于逐条核对用例结果。 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// Redis 测试夹具
// ---------------------------------------------------------------------------

/**
 * 尝试连接本机 Redis 并构造隔离前缀的 RedisRunStore。
 *
 * 验证前提：phpredis 可用且 127.0.0.1:6379 可连；否则返回 null 供用例跳过。
 *
 * @return array{0: RedisRunStore, 1: WorkflowRegistry}|null
 */
function makeRedisRunStore(): ?array
{
    if (!extension_loaded('redis')) {
        return null;
    }

    $redis = new Redis();
    try {
        $redis->connect('127.0.0.1', 6379, 1.0);
    } catch (Throwable) {
        return null;
    }

    $registry = new WorkflowRegistry();
    $registry->register('cas', static fn () => WorkflowDefinition::create('cas', '1.0.0')
        ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())));

    $prefix = 'workflow:run:cas_test:' . bin2hex(random_bytes(4)) . ':';
    $store = new RedisRunStore($redis, $registry, $prefix, 3600);

    return [$store, $registry];
}

/**
 * 构造处于 WAITING 状态、带 pauseNodeId 的 WorkflowRun 夹具。
 *
 * 用于模拟 HITL 暂停场景，供 CAS 与 TTL 用例读写 Redis key。
 */
function makeWaitingRun(WorkflowRegistry $registry, string $runId): WorkflowRun
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

// ---------------------------------------------------------------------------
// saveIfStatus CAS 语义
// ---------------------------------------------------------------------------

/**
 * 验证：Redis 中 run 当前为 WAITING 时，saveIfStatus(..., WAITING) 应成功并写入 RUNNING。
 *
 * 为何重要：resume 认领依赖「仅当仍为 WAITING 才原子更新」，避免并发双写。
 */
function testRedisCasSucceedsWhenStatusMatches(): void
{
    $setup = makeRedisRunStore();
    if ($setup === null) {
        echo "[SKIP] redis cas succeeds (no redis)\n";

        return;
    }

    [$store, $registry] = $setup;
    $run = makeWaitingRun($registry, 'run_cas_ok');

    $store->save($run);
    $run->status = RunStatus::RUNNING;
    $run->pauseNodeId = null;
    $run->updatedAt = WorkflowRunTime::now();

    assertTrue($store->saveIfStatus($run, RunStatus::WAITING), 'cas should succeed');
    assertTrue($store->find('run_cas_ok')?->status === RunStatus::RUNNING, 'status updated');

    pass('redis cas succeeds when status matches');
}

/**
 * 验证：期望状态与实际 Redis 中状态不一致时，saveIfStatus 返回 false 且不修改存储。
 *
 * 为何重要：防止基于过期快照覆盖他人已认领的 run。
 */
function testRedisCasFailsWhenStatusMismatch(): void
{
    $setup = makeRedisRunStore();
    if ($setup === null) {
        echo "[SKIP] redis cas mismatch (no redis)\n";

        return;
    }

    [$store, $registry] = $setup;
    $run = makeWaitingRun($registry, 'run_cas_mismatch');

    $store->save($run);
    $run->status = RunStatus::RUNNING;
    assertTrue(!$store->saveIfStatus($run, RunStatus::RUNNING), 'expected WAITING not RUNNING');
    assertTrue($store->find('run_cas_mismatch')?->status === RunStatus::WAITING, 'unchanged');

    pass('redis cas fails when status mismatch');
}

/**
 * 验证：Redis 中不存在对应 run key 时，saveIfStatus 直接失败。
 *
 * 为何重要：避免对已过期或从未持久化的 run 执行无效 CAS。
 */
function testRedisCasFailsWhenKeyMissing(): void
{
    $setup = makeRedisRunStore();
    if ($setup === null) {
        echo "[SKIP] redis cas missing key (no redis)\n";

        return;
    }

    [$store, $registry] = $setup;
    $run = makeWaitingRun($registry, 'run_cas_missing');

    assertTrue(!$store->saveIfStatus($run, RunStatus::WAITING), 'missing key');

    pass('redis cas fails when key missing');
}

/**
 * 验证：两个 Worker 先后对同一 WAITING run 执行 CAS 认领时，仅第一次成功。
 *
 * 为何重要：生产环境多实例 resume 必须保证「先到先得」，第二次应被拒绝。
 */
function testRedisCasSecondClaimFails(): void
{
    $setup = makeRedisRunStore();
    if ($setup === null) {
        echo "[SKIP] redis cas double claim (no redis)\n";

        return;
    }

    [$store, $registry] = $setup;
    $run = makeWaitingRun($registry, 'run_cas_double');

    $store->save($run);

    $first = $store->find('run_cas_double');
    assertTrue($first !== null, 'run loaded');
    $first->status = RunStatus::RUNNING;
    $first->pauseNodeId = null;
    $first->updatedAt = WorkflowRunTime::now();
    assertTrue($store->saveIfStatus($first, RunStatus::WAITING), 'first claim');

    $second = $store->find('run_cas_double');
    assertTrue($second !== null, 'run loaded again');
    $second->status = RunStatus::RUNNING;
    $second->pauseNodeId = null;
    assertTrue(!$store->saveIfStatus($second, RunStatus::WAITING), 'second claim fails');

    pass('redis cas second claim fails');
}

// ---------------------------------------------------------------------------
// WAITING 状态 TTL 策略
// ---------------------------------------------------------------------------

/**
 * 验证：WAITING 状态的 run key 在 Redis 中不应设置过期时间；转为 COMPLETED 后应用配置 TTL。
 *
 * 为何重要：HITL 暂停可能持续很久，若 WAITING key 过期会导致 resume 找不到 run。
 */
function testRedisWaitingRunHasNoTtl(): void
{
    $setup = makeRedisRunStore();
    if ($setup === null) {
        echo "[SKIP] redis waiting ttl (no redis)\n";

        return;
    }

    [$store, $registry] = $setup;
    $run = makeWaitingRun($registry, 'run_waiting_ttl');
    $store->save($run);

    // 通过反射读取私有 prefix/redis，直接检查底层 key 的 TTL
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
    assertTrue($ttl < 0, 'WAITING key must not expire (ttl=-1 permanent or -2 missing)');
    assertTrue($store->find('run_waiting_ttl') !== null, 'WAITING run still readable');

    $run->status = RunStatus::COMPLETED;
    $run->pauseNodeId = null;
    $run->updatedAt = WorkflowRunTime::now();
    $store->save($run);

    $ttlAfter = (int) $redis->ttl($key);
    assertTrue($ttlAfter > 0, 'non-WAITING run applies configured TTL');

    pass('redis waiting run has no ttl');
}

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'redis cas succeeds when status matches' => 'testRedisCasSucceedsWhenStatusMatches',
    'redis cas fails when status mismatch' => 'testRedisCasFailsWhenStatusMismatch',
    'redis cas fails when key missing' => 'testRedisCasFailsWhenKeyMissing',
    'redis cas second claim fails' => 'testRedisCasSecondClaimFails',
    'redis waiting run has no ttl' => 'testRedisWaitingRunHasNoTtl',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    ++$passed;
}

echo "\nAll {$passed} RedisRunStore CAS tests passed.\n";
