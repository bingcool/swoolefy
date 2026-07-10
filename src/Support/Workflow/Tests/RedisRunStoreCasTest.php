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
 * RedisRunStore Lua CAS 单测。
 *
 * 需要本机 Redis + phpredis 扩展；不可用时跳过。
 *
 * 运行：php src/Support/Workflow/Tests/RedisRunStoreCasTest.php
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

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

/** @return array{0: RedisRunStore, 1: WorkflowRegistry}|null */
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

$tests = [
    'redis cas succeeds when status matches' => 'testRedisCasSucceedsWhenStatusMatches',
    'redis cas fails when status mismatch' => 'testRedisCasFailsWhenStatusMismatch',
    'redis cas fails when key missing' => 'testRedisCasFailsWhenKeyMissing',
    'redis cas second claim fails' => 'testRedisCasSecondClaimFails',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    ++$passed;
}

echo "\nAll {$passed} RedisRunStore CAS tests passed.\n";
