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

namespace Swoolefy\Support\Job;

use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * 执行 Handler，并将 Result 映射为 requeue / dead 回调。
 *
 * 传输层 ACK / NACK 仍由自定义进程负责；本类只决定业务侧重试语义。
 */
final class JobRunner
{
    public function __construct(
        private readonly JobRetryPolicy $policy = new JobRetryPolicy(),
        private readonly float $timeoutSeconds = 120,
    ) {
    }

    public function policy(): JobRetryPolicy
    {
        return $this->policy;
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }

    /**
     * 执行单个 Handler，并将业务结果映射为进程侧回调。
     *
     * 本方法**不**直接操作 Redis/AMQP/Kafka；传输层 ACK、延迟投递、死信落盘
     * 全部由调用方（自定义进程）通过 $requeue / $dead 注入，保证自由度在进程侧。
     *
     * @param JobHandlerInterface $handler 业务处理器
     * @param JobEnvelope $job 当前投递的信封（含 attempt / maxAttempts）
     * @param callable(JobEnvelope $next, int $delayMs): void $requeue
     *        可重试时调用（Handler 返回 RETRY，且 attempt 尚未耗尽）。
     *        - $next：已把 attempt+1 的新信封，进程应投递**这份**而非原 $job
     *        - $delayMs：建议延迟毫秒（Handler 的 retryAfterMs 优先，否则按 JobRetryPolicy 指数退避）
     *        进程侧典型实现：
     *        - Redis Queue：ceil($delayMs/1000) 后 $queue->retry($next->toArray(), $seconds)
     *        - AMQP：publish 到 delay 队列或原队列，再 ack 原消息
     *        - Kafka：producer->produce（若无原生 delay，可忽略 $delayMs 立即 republish）
     * @param callable(JobEnvelope $failed, string $error): void $dead
     *        最终失败时调用，触发场景：
     *        1) Handler 返回 FAIL（业务不可恢复）
     *        2) Handler 返回 RETRY 但 attempt 已达 maxAttempts
     *        3) runRegistered 时 jobType 未注册（见下方）
     *        - $failed：失败时的信封（一般为当前 attempt，未再 +1）
     *        - $error：失败原因文案
     *        进程侧典型实现：RedisDeadLetter::push / 写日志 / 依赖 AMQP DLX / Kafka 旁路 topic。
     *        注意：ACK/NACK 仍由进程在回调内外自行处理，Runner 不管传输确认。
     *
     * @return JobRunOutcome SUCCESS | REQUEUED | DEAD | DISCARDED
     */
    public function run(
        JobHandlerInterface $handler,
        JobEnvelope $job,
        callable $requeue,
        callable $dead,
    ): JobRunOutcome {
        // 取信封与全局策略中较小的 maxAttempts，避免单条消息无限重试
        $effectiveMax = min($job->maxAttempts, $this->policy->maxAttempts);
        $policy = new JobRetryPolicy(
            maxAttempts: $effectiveMax,
            baseDelayMs: $this->policy->baseDelayMs,
            backoffMultiplier: $this->policy->backoffMultiplier,
            maxDelayMs: $this->policy->maxDelayMs,
            jitterRatio: $this->policy->jitterRatio,
        );

        try {
            $result = $handler->handle($job);
        } catch (Throwable $e) {
            // 未捕获异常按可重试处理，交给下方 RETRY 分支
            $result = JobResult::retry($e->getMessage());
        }

        // SUCCESS/DISCARD：进程侧 ACK；FAIL→死信；RETRY→重入队或耗尽死信
        return match ($result->status) {
            JobResultStatus::SUCCESS => JobRunOutcome::SUCCESS,
            JobResultStatus::DISCARD => JobRunOutcome::DISCARDED,
            JobResultStatus::FAIL => $this->toDead($job, $result->error ?? 'fail', $dead),
            JobResultStatus::RETRY => $this->toRetryOrDead($job, $result, $policy, $requeue, $dead),
        };
    }

    /**
     * 从 Registry 按 jobType 解析 Handler 后执行（多类型共进程）。
     *
     * 未知 jobType → 直接走 $dead（毒消息 / 误路由），不静默丢弃。
     *
     * @param JobHandlerRegistry $registry jobType → Handler 映射
     * @param JobEnvelope $job 当前信封
     * @param callable(JobEnvelope $next, int $delayMs): void $requeue
     *        语义同 {@see run()}：$next 为 attempt+1 的信封，$delayMs 为建议延迟（毫秒）。
     *        由进程对接具体队列的「再投递 / 延迟重试」能力。
     * @param callable(JobEnvelope $failed, string $error): void $dead
     *        语义同 {@see run()}：最终失败或未注册 jobType 时调用。
     *        $error 在未注册时形如 `unregistered jobType: xxx`。
     *
     * @return JobRunOutcome SUCCESS | REQUEUED | DEAD | DISCARDED
     */
    public function runRegistered(
        JobHandlerRegistry $registry,
        JobEnvelope $job,
        callable $requeue,
        callable $dead,
    ): JobRunOutcome {
        $handler = $registry->get($job->jobType);
        if ($handler === null) {
            // 未注册类型视为毒消息/误路由，进死信而非静默丢弃
            return $this->toDead($job, 'unregistered jobType: ' . $job->jobType, $dead);
        }

        return $this->run($handler, $job, $requeue, $dead);
    }

    /**
     * RETRY 分支：未耗尽则 $requeue($next, $delayMs)，否则转 $dead。
     *
     * @param callable(JobEnvelope $next, int $delayMs): void $requeue 见 {@see run()}
     * @param callable(JobEnvelope $failed, string $error): void $dead 见 {@see run()}
     */
    private function toRetryOrDead(
        JobEnvelope $job,
        JobResult $result,
        JobRetryPolicy $policy,
        callable $requeue,
        callable $dead,
    ): JobRunOutcome {
        $error = $result->error ?? 'retry';
        // 已达 maxAttempts：不再调度，转死信
        if (!$policy->shouldRetry($job->attempt)) {
            return $this->toDead($job, $error, $dead);
        }

        // 下一跳 attempt+1；延迟优先用 Handler 指定的 retryAfterMs
        $next = $job->withAttempt($job->attempt + 1);
        $delayMs = $result->retryAfterMs ?? $policy->delayMsForAttempt($job->attempt);

        SupportLog::warning('job', 'job.retry', [
            'jobId' => $job->jobId,
            'jobType' => $job->jobType,
            'attempt' => $job->attempt,
            'nextAttempt' => $next->attempt,
            'delayMs' => $delayMs,
            'error' => $error,
        ]);

        // 由进程注入的 callable 对接 Queue::retry / AMQP republish 等
        $requeue($next, max(0, $delayMs));

        return JobRunOutcome::REQUEUED;
    }

    /**
     * 最终失败：打日志后调用进程侧 $dead。
     *
     * @param callable(JobEnvelope $failed, string $error): void $dead 见 {@see run()}
     */
    private function toDead(JobEnvelope $job, string $error, callable $dead): JobRunOutcome
    {
        SupportLog::warning('job', 'job.dead', [
            'jobId' => $job->jobId,
            'jobType' => $job->jobType,
            'attempt' => $job->attempt,
            'error' => $error,
        ]);
        // 死信落盘/日志由进程侧 dead 回调实现（如 RedisDeadLetter::push）
        $dead($job, $error);

        return JobRunOutcome::DEAD;
    }
}
