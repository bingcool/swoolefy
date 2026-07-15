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
 * Job Phase 1 回归：信封序列化、重试策略、Runner 结果映射、Publisher 投递。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | JobEnvelope | toArray/fromArray 往返、jobType 必填、wrapLegacy 兼容旧载荷 |
 * | JobRetryPolicy | 指数退避 delay、shouldRetry 次数边界 |
 * | JobRunner | SUCCESS / REQUEUED / DEAD 三分支；attempt 递增；异常当可重试 |
 * | JobPublisher | dispatch 组装信封并回调发布 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Job/Tests/JobPhase1Test.php
 * # 或
 * composer test:job
 * ```
 *
 * 说明：Stub Handler 均为本文件内假实现，不依赖 Redis / AMQP 等外部中间件。
 */

use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobPublisher;
use Swoolefy\Support\Job\JobResult;
use Swoolefy\Support\Job\JobRetryPolicy;
use Swoolefy\Support\Job\JobRunOutcome;
use Swoolefy\Support\Job\JobRunner;
use Swoolefy\Support\Job\Exception\JobException;
use Swoolefy\Support\SupportLog;

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
// JobEnvelope：序列化往返与校验
// ---------------------------------------------------------------------------

/**
 * 验证 JobEnvelope::make → toArray → fromArray 往返后字段一致：
 * jobId、jobType、payload、meta、maxAttempts（来自 RetryPolicy）、withAttempt 不可变副本。
 */
function testEnvelopeRoundTrip(): void
{
    $job = JobEnvelope::make('order.paid.notify', ['orderId' => 1], [
        'tenantId' => 't1',
        'idempotencyKey' => 'order:1:paid-notify',
    ], new JobRetryPolicy(maxAttempts: 3));

    $again = JobEnvelope::fromArray($job->toArray());
    assertTrue($again->jobId === $job->jobId, 'jobId stable');
    assertTrue($again->jobType === 'order.paid.notify', 'jobType');
    assertTrue(($again->payload['orderId'] ?? null) === 1, 'payload');
    assertTrue($again->metaString('tenantId') === 't1', 'meta');
    assertTrue($again->maxAttempts === 3, 'maxAttempts from policy');
    assertTrue($again->withAttempt(2)->attempt === 2, 'withAttempt');

    pass('envelope round trip');
}

/**
 * 验证 fromArray 缺少 jobType 时抛 JobException，防止无类型任务进入队列。
 */
function testEnvelopeRequiresJobType(): void
{
    try {
        JobEnvelope::fromArray(['payload' => []]);
        assertTrue(false, 'should throw');
    } catch (JobException $e) {
        assertTrue(str_contains($e->getMessage(), 'jobType'), 'message');
    }

    pass('envelope requires jobType');
}

/**
 * 验证 wrapLegacy：普通数组包装为信封；已是信封数组时保留原 jobType、忽略传入 type 参数。
 */
function testWrapLegacy(): void
{
    $job = JobEnvelope::wrapLegacy(['name' => 'legacy'], 'demo.legacy');
    assertTrue($job->jobType === 'demo.legacy', 'legacy type');
    assertTrue(($job->payload['name'] ?? null) === 'legacy', 'legacy payload');

    $nested = JobEnvelope::wrapLegacy(JobEnvelope::make('x.y', ['a' => 1])->toArray(), 'ignored');
    assertTrue($nested->jobType === 'x.y', 'already envelope');

    pass('wrap legacy');
}

// ---------------------------------------------------------------------------
// JobRetryPolicy：退避与重试次数
// ---------------------------------------------------------------------------

/**
 * 验证指数退避：baseDelay × multiplier^(attempt-1)；jitter=0 时 delay 可预测；
 * shouldRetry 在末次 attempt 返回 false。
 */
function testRetryBackoffIncreases(): void
{
    $policy = new JobRetryPolicy(
        maxAttempts: 5,
        baseDelayMs: 1000,
        backoffMultiplier: 2.0,
        maxDelayMs: 300_000,
        jitterRatio: 0.0,
    );

    assertTrue($policy->delayMsForAttempt(1) === 1000, 'attempt1');
    assertTrue($policy->delayMsForAttempt(2) === 2000, 'attempt2');
    assertTrue($policy->delayMsForAttempt(3) === 4000, 'attempt3');
    assertTrue($policy->shouldRetry(4) === true, 'can retry after 4');
    assertTrue($policy->shouldRetry(5) === false, 'exhausted at 5');

    pass('retry backoff');
}

// ---------------------------------------------------------------------------
// 测试替身：JobRunner 各分支用的 Handler 假实现
// ---------------------------------------------------------------------------

/** 始终返回 success，用于验证 Runner 成功路径不触发 requeue / dead-letter */
final class StubSuccessHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.ok'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::success();
    }
}

/** 前两次返回 retry，第三次 success；用于多轮重试场景（本文件未直接调用，供扩展） */
final class StubRetryThenOkHandler implements JobHandlerInterface
{
    public int $calls = 0;

    public function types(): array
    {
        return ['stub.retry'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        ++$this->calls;
        if ($job->attempt < 3) {
            return JobResult::retry('transient', 50);
        }

        return JobResult::success();
    }
}

/** 始终返回 retry，用于验证 requeue 与耗尽后进死信 */
final class StubAlwaysRetryHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.poison'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::retry('always');
    }
}

/** 返回 fail，用于验证不可重试失败直接进死信 */
final class StubFailHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.fail'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::fail('bad payload');
    }
}

/** handle 抛异常，用于验证 Runner 将未捕获异常视为可重试 */
final class StubThrowHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['stub.throw'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        throw new RuntimeException('boom');
    }
}

// ---------------------------------------------------------------------------
// JobRunner：SUCCESS / REQUEUED / DEAD 与副作用回调
// ---------------------------------------------------------------------------

/**
 * 验证 Handler 返回 success 时 outcome 为 SUCCESS，且不调用 requeue / dead 回调。
 */
function testRunnerSuccessNoRequeue(): void
{
    $requeued = 0;
    $dead = 0;
    $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0));
    $job = JobEnvelope::make('stub.ok', []);
    $outcome = $runner->run(
        new StubSuccessHandler(),
        $job,
        static function () use (&$requeued): void {
            ++$requeued;
        },
        static function () use (&$dead): void {
            ++$dead;
        },
    );

    assertTrue($outcome === JobRunOutcome::SUCCESS, 'success outcome');
    assertTrue($requeued === 0 && $dead === 0, 'no side effects');

    pass('runner success');
}

/**
 * 验证 Handler 返回 retry 时 outcome 为 REQUEUED；
 * 回调收到 attempt+1 的信封与按策略计算的 delayMs。
 */
function testRunnerRequeueIncrementsAttempt(): void
{
    $captured = null;
    $delay = null;
    $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 5, baseDelayMs: 100, jitterRatio: 0.0));
    $job = JobEnvelope::make('stub.retry', [], [], new JobRetryPolicy(maxAttempts: 5));
    $outcome = $runner->run(
        new StubAlwaysRetryHandler(),
        $job,
        static function (JobEnvelope $next, int $delayMs) use (&$captured, &$delay): void {
            $captured = $next;
            $delay = $delayMs;
        },
        static function (): void {
            throw new RuntimeException('should not dead');
        },
    );

    assertTrue($outcome === JobRunOutcome::REQUEUED, 'requeued');
    assertTrue($captured instanceof JobEnvelope && $captured->attempt === 2, 'attempt+1');
    assertTrue($delay === 100, 'delay for attempt 1');

    pass('runner requeue attempt');
}

/**
 * 验证已达 maxAttempts 仍 retry 时 outcome 为 DEAD，dead 回调收到错误信息。
 */
function testRunnerExhaustedGoesDead(): void
{
    $deadError = null;
    $runner = new JobRunner(new JobRetryPolicy(maxAttempts: 2, jitterRatio: 0.0));
    $job = JobEnvelope::make('stub.poison', [], [], new JobRetryPolicy(maxAttempts: 2))
        ->withAttempt(2);
    $outcome = $runner->run(
        new StubAlwaysRetryHandler(),
        $job,
        static function (): void {
            throw new RuntimeException('should not requeue');
        },
        static function (JobEnvelope $j, string $error) use (&$deadError): void {
            $deadError = $error;
        },
    );

    assertTrue($outcome === JobRunOutcome::DEAD, 'dead');
    assertTrue($deadError === 'always', 'error');

    pass('runner exhausted dead');
}

/**
 * 验证 Handler 显式 fail 时 outcome 为 DEAD，不进入 requeue 路径。
 */
function testRunnerFailGoesDead(): void
{
    $outcome = (new JobRunner())->run(
        new StubFailHandler(),
        JobEnvelope::make('stub.fail', []),
        static function (): void {
            throw new RuntimeException('no requeue');
        },
        static function (): void {
        },
    );
    assertTrue($outcome === JobRunOutcome::DEAD, 'fail→dead');

    pass('runner fail dead');
}

/**
 * 验证 Handler 抛异常时 Runner 按可重试处理，outcome 为 REQUEUED。
 */
function testRunnerExceptionTreatedAsRetry(): void
{
    $outcome = (new JobRunner(new JobRetryPolicy(maxAttempts: 3, jitterRatio: 0.0)))->run(
        new StubThrowHandler(),
        JobEnvelope::make('stub.throw', []),
        static function (): void {
        },
        static function (): void {
            throw new RuntimeException('no dead');
        },
    );
    assertTrue($outcome === JobRunOutcome::REQUEUED, 'throw→retry');

    pass('runner exception retry');
}

// ---------------------------------------------------------------------------
// JobPublisher：组装信封并发布
// ---------------------------------------------------------------------------

/**
 * 验证 dispatch 生成完整信封数组并调用 publish 回调；
 * 返回的 JobEnvelope 与 published 数据 jobId 一致，maxAttempts 来自 Publisher 策略。
 */
function testPublisherDispatch(): void
{
    $published = null;
    $publisher = new JobPublisher(static function (array $data) use (&$published): void {
        $published = $data;
    }, new JobRetryPolicy(maxAttempts: 4));

    $job = $publisher->dispatch('order.paid.notify', ['orderId' => 9], ['tenantId' => 't9']);
    assertTrue(is_array($published), 'published');
    assertTrue(($published['jobType'] ?? null) === 'order.paid.notify', 'type');
    assertTrue(($published['payload']['orderId'] ?? null) === 9, 'payload');
    assertTrue(($published['maxAttempts'] ?? null) === 4, 'maxAttempts');
    assertTrue($job->jobId === ($published['jobId'] ?? null), 'same id');

    pass('publisher dispatch');
}

// ---------------------------------------------------------------------------
// 执行入口：静默 SupportLog，逐条跑用例
// ---------------------------------------------------------------------------

SupportLog::setTestHandler(static function (): void {
});

$tests = [
    'envelope round trip' => 'testEnvelopeRoundTrip',
    'envelope requires jobType' => 'testEnvelopeRequiresJobType',
    'wrap legacy' => 'testWrapLegacy',
    'retry backoff' => 'testRetryBackoffIncreases',
    'runner success' => 'testRunnerSuccessNoRequeue',
    'runner requeue attempt' => 'testRunnerRequeueIncrementsAttempt',
    'runner exhausted dead' => 'testRunnerExhaustedGoesDead',
    'runner fail dead' => 'testRunnerFailGoesDead',
    'runner exception retry' => 'testRunnerExceptionTreatedAsRetry',
    'publisher dispatch' => 'testPublisherDispatch',
];

$passed = 0;
foreach ($tests as $label => $fn) {
    $fn();
    ++$passed;
}

SupportLog::resetTestHandler();

echo "\nAll {$passed} Job Phase 1 tests passed.\n";
