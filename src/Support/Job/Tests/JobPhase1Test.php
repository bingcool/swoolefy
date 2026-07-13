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
 * Job Phase 1 回归：信封、重试策略、Runner 结果映射。
 *
 * 运行：php src/Support/Job/Tests/JobPhase1Test.php
 * 或：composer test:job
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

function testWrapLegacy(): void
{
    $job = JobEnvelope::wrapLegacy(['name' => 'legacy'], 'demo.legacy');
    assertTrue($job->jobType === 'demo.legacy', 'legacy type');
    assertTrue(($job->payload['name'] ?? null) === 'legacy', 'legacy payload');

    $nested = JobEnvelope::wrapLegacy(JobEnvelope::make('x.y', ['a' => 1])->toArray(), 'ignored');
    assertTrue($nested->jobType === 'x.y', 'already envelope');

    pass('wrap legacy');
}

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
