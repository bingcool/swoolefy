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
 * Job Phase 2 回归：Registry / Config / RedisDeadLetter 重放。
 *
 * 运行：php src/Support/Job/Tests/JobPhase2Test.php
 * 或：composer test:job
 */

use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobConfig;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobHandlerRegistry;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\Job\RedisDeadLetter;
use Swoolefy\Support\Job\Exception\JobException;
use Swoolefy\Support\SupportLog;
use Test\Module\Job\OrderExportHandler;
use Test\Module\Job\OrderPaidNotifyHandler;

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

/** 死信单测用的最小 Redis List 假实现。 */
final class FakeRedisList
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    public function lPush(string $key, string $value): int
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);

        return count($this->lists[$key]);
    }

    public function rPop(string $key): string|false
    {
        if (($this->lists[$key] ?? []) === []) {
            return false;
        }

        return array_pop($this->lists[$key]);
    }

    public function lLen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }
}

final class EchoHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['demo.echo'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::success();
    }
}

function testRegistryRoutesTypes(): void
{
    $registry = JobComponentFactory::registry(
        new OrderPaidNotifyHandler(),
        new OrderExportHandler(),
        new EchoHandler(),
    );

    assertTrue($registry->count() === 3, 'three types');
    assertTrue($registry->has('order.paid.notify'), 'paid');
    assertTrue($registry->has('order.export'), 'export');
    assertTrue($registry->require('demo.echo') instanceof EchoHandler, 'echo');

    try {
        $registry->require('missing.type');
        assertTrue(false, 'should throw');
    } catch (JobException $e) {
        assertTrue(str_contains($e->getMessage(), 'missing.type'), 'msg');
    }

    pass('registry routes types');
}

function testRunnerRunRegistered(): void
{
    $registry = (new JobHandlerRegistry())->register(new OrderPaidNotifyHandler());
    $runner = new JobRunner();
    $outcome = $runner->runRegistered(
        $registry,
        JobEnvelope::make('order.paid.notify', ['orderId' => 1]),
        static function (): void {
            throw new RuntimeException('no requeue');
        },
        static function (): void {
            throw new RuntimeException('no dead');
        },
    );
    assertTrue($outcome === JobRunOutcome::SUCCESS, 'success');

    $dead = false;
    $outcome2 = $runner->runRegistered(
        $registry,
        JobEnvelope::make('unknown.type', []),
        static function (): void {
        },
        static function () use (&$dead): void {
            $dead = true;
        },
    );
    assertTrue($outcome2 === JobRunOutcome::DEAD && $dead, 'unknown→dead');

    pass('runner runRegistered');
}

function testJobConfigFromArray(): void
{
    $config = JobConfig::fromArray([
        'job' => [
            'default_max_attempts' => 7,
            'base_delay_ms' => 500,
            'backoff_multiplier' => 3.0,
            'max_delay_ms' => 10000,
            'handler_timeout_seconds' => 60,
            'dead_letter' => [
                'driver' => 'redis_list',
                'redis_key_prefix' => 'job:dead:test:',
            ],
        ],
    ]);

    assertTrue($config->defaultMaxAttempts() === 7, 'maxAttempts');
    assertTrue($config->baseDelayMs() === 500, 'baseDelay');
    assertTrue($config->backoffMultiplier() === 3.0, 'multiplier');
    assertTrue($config->deadLetterRedisKeyPrefix() === 'job:dead:test:', 'prefix');
    assertTrue($config->retryPolicy()->maxAttempts === 7, 'policy');

    $runner = JobComponentFactory::runner($config);
    assertTrue($runner->policy()->maxAttempts === 7, 'factory runner');

    pass('job config fromArray');
}

function testRedisDeadLetterReplay(): void
{
    $redis = new FakeRedisList();
    $dlq = new RedisDeadLetter($redis, 'job:dead:');
    $job = JobEnvelope::make('order.export', ['orderId' => 42])->withAttempt(5);
    $dlq->push($job, 'exhausted', 'default');
    assertTrue($dlq->length() === 1, 'len 1');

    $published = [];
    $n = $dlq->replay(static function (array $data) use (&$published): void {
        $published[] = $data;
    }, 'default', 5);

    assertTrue($n === 1, 'replayed 1');
    assertTrue($dlq->length() === 0, 'empty after');
    assertTrue(($published[0]['attempt'] ?? null) === 1, 'attempt reset');
    assertTrue(($published[0]['payload']['orderId'] ?? null) === 42, 'payload');

    pass('redis dead letter replay');
}

SupportLog::setTestHandler(static function (): void {
});

$tests = [
    'registry routes types' => 'testRegistryRoutesTypes',
    'runner runRegistered' => 'testRunnerRunRegistered',
    'job config fromArray' => 'testJobConfigFromArray',
    'redis dead letter replay' => 'testRedisDeadLetterReplay',
];

$passed = 0;
foreach ($tests as $label => $fn) {
    $fn();
    ++$passed;
}

SupportLog::resetTestHandler();

echo "\nAll {$passed} Job Phase 2 tests passed.\n";
