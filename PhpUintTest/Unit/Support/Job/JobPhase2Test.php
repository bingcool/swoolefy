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

namespace PhpUintTest\Unit\Support\Job;

use RuntimeException;
use Swoolefy\Support\Job\Exception\JobException;
use Swoolefy\Support\Job\JobComponentFactory;
use Swoolefy\Support\Job\JobConfig;
use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobHandlerRegistry;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\Job\RedisDeadLetter;
use Swoolefy\Support\Job\Tests\Fixtures\OrderExportHandler;
use Swoolefy\Support\Job\Tests\Fixtures\OrderPaidNotifyHandler;
use Swoolefy\Support\SupportLog;
use PhpUintTest\TestCase;

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
 * 说明：Redis 使用本文件 {@see FakeRedisList} 内存假实现；Handler 来自 Support Job Fixtures。
 */
final class JobPhase2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SupportLog::setTestHandler(static function (): void {
        });
    }

    protected function tearDown(): void
    {
        SupportLog::resetTestHandler();
        parent::tearDown();
    }

    /**
     * 验证 JobComponentFactory::registry 聚合多 Handler；
     * has / require 正确路由；require 未知类型抛 JobException 且消息含类型名。
     */
    public function testRegistryRoutesTypes(): void
    {
        $registry = JobComponentFactory::registry(
            new OrderPaidNotifyHandler(),
            new OrderExportHandler(),
            new EchoHandler(),
        );

        $this->assertTrue($registry->count() === 3, 'three types');
        $this->assertTrue($registry->has('order.paid.notify'), 'paid');
        $this->assertTrue($registry->has('order.export'), 'export');
        $this->assertTrue($registry->require('demo.echo') instanceof EchoHandler, 'echo');

        try {
            $registry->require('missing.type');
            $this->assertTrue(false, 'should throw');
        } catch (JobException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'missing.type'), 'msg');
        }
    }

    /**
     * 验证 runRegistered：已注册 order.paid.notify 返回 SUCCESS；
     * 未知 unknown.type 返回 DEAD 并触发 dead 回调。
     */
    public function testRunnerRunRegistered(): void
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
        $this->assertTrue($outcome === JobRunOutcome::SUCCESS, 'success');

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
        $this->assertTrue($outcome2 === JobRunOutcome::DEAD && $dead, 'unknown→dead');
    }

    /**
     * 验证 JobConfig::fromArray 正确映射 default_max_attempts、退避参数、死信 Redis 前缀；
     * retryPolicy 与 JobComponentFactory::runner 使用同一 maxAttempts。
     */
    public function testJobConfigFromArray(): void
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

        $this->assertTrue($config->defaultMaxAttempts() === 7, 'maxAttempts');
        $this->assertTrue($config->baseDelayMs() === 500, 'baseDelay');
        $this->assertTrue($config->backoffMultiplier() === 3.0, 'multiplier');
        $this->assertTrue($config->deadLetterRedisKeyPrefix() === 'job:dead:test:', 'prefix');
        $this->assertTrue($config->retryPolicy()->maxAttempts === 7, 'policy');

        $runner = JobComponentFactory::runner($config);
        $this->assertTrue($runner->policy()->maxAttempts === 7, 'factory runner');
        // handler_timeout_seconds 贯通到 JobRunner（TimeoutGuard 预算）
        $this->assertTrue($runner->timeoutSeconds() === 60.0, 'timeout seconds');
    }

    /**
     * 验证死信 push 后 length=1；replay 消费一条、队列清空；
     * 重放数据 attempt 重置为 1，payload 保持不变。
     */
    public function testRedisDeadLetterReplay(): void
    {
        $redis = new FakeRedisList();
        $dlq = new RedisDeadLetter($redis, 'job:dead:');
        $job = JobEnvelope::make('order.export', ['orderId' => 42])->withAttempt(5);
        $dlq->push($job, 'exhausted', 'default');
        $this->assertTrue($dlq->length() === 1, 'len 1');

        $published = [];
        $n = $dlq->replay(static function (array $data) use (&$published): void {
            $published[] = $data;
        }, 'default', 5);

        $this->assertTrue($n === 1, 'replayed 1');
        $this->assertTrue($dlq->length() === 0, 'empty after');
        $this->assertTrue(($published[0]['attempt'] ?? null) === 1, 'attempt reset');
        $this->assertTrue(($published[0]['payload']['orderId'] ?? null) === 42, 'payload');
    }
}

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
