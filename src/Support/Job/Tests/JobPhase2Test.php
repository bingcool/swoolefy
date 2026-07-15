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
 * Job Phase 2 回归：Handler 注册表、配置工厂、Redis 死信重放。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | JobHandlerRegistry | 多类型注册、has/require、缺失类型抛 JobException |
 * | JobRunner::runRegistered | 已注册类型 SUCCESS；未知类型 DEAD |
 * | JobConfig / JobComponentFactory | fromArray 映射、retryPolicy 与 runner 工厂 |
 * | RedisDeadLetter | push / replay 清空队列、attempt 重置为 1 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Job/Tests/JobPhase2Test.php
 * # 或
 * composer test:job
 * ```
 *
 * 说明：Redis 使用本文件 {@see FakeRedisList} 内存假实现；业务 Handler 来自 Test\Module\Job。
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

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 打印通过标记，便于 CLI 逐条扫结果 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// 测试替身：内存 Redis List，供 RedisDeadLetter 单测
// ---------------------------------------------------------------------------

/**
 * 死信单测用的最小 Redis List 假实现。
 *
 * 仅实现 lPush / rPop / lLen，语义与 Redis 列表 FIFO（rPop 消费）一致。
 */
final class FakeRedisList
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    /** 从列表头部入队，返回当前列表长度 */
    public function lPush(string $key, string $value): int
    {
        $this->lists[$key] ??= [];
        array_unshift($this->lists[$key], $value);

        return count($this->lists[$key]);
    }

    /** 从列表尾部出队；空列表返回 false */
    public function rPop(string $key): string|false
    {
        if (($this->lists[$key] ?? []) === []) {
            return false;
        }

        return array_pop($this->lists[$key]);
    }

    /** 返回指定 key 的列表元素个数 */
    public function lLen(string $key): int
    {
        return count($this->lists[$key] ?? []);
    }
}

// ---------------------------------------------------------------------------
// 测试替身：简单成功 Handler
// ---------------------------------------------------------------------------

/** 注册表路由用：类型 demo.echo，handle 恒返回 success */
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

// ---------------------------------------------------------------------------
// JobHandlerRegistry：类型注册与查找
// ---------------------------------------------------------------------------

/**
 * 验证 JobComponentFactory::registry 聚合多 Handler；
 * has / require 正确路由；require 未知类型抛 JobException 且消息含类型名。
 */
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

// ---------------------------------------------------------------------------
// JobRunner::runRegistered：按注册表执行
// ---------------------------------------------------------------------------

/**
 * 验证 runRegistered：已注册 order.paid.notify 返回 SUCCESS；
 * 未知 unknown.type 返回 DEAD 并触发 dead 回调。
 */
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

// ---------------------------------------------------------------------------
// JobConfig：配置解析与组件工厂
// ---------------------------------------------------------------------------

/**
 * 验证 JobConfig::fromArray 正确映射 default_max_attempts、退避参数、死信 Redis 前缀；
 * retryPolicy 与 JobComponentFactory::runner 使用同一 maxAttempts。
 */
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

// ---------------------------------------------------------------------------
// RedisDeadLetter：入队与重放
// ---------------------------------------------------------------------------

/**
 * 验证死信 push 后 length=1；replay 消费一条、队列清空；
 * 重放数据 attempt 重置为 1，payload 保持不变。
 */
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

// ---------------------------------------------------------------------------
// 执行入口：静默 SupportLog，逐条跑用例
// ---------------------------------------------------------------------------

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
