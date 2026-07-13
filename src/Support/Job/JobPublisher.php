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

/**
 * 组装信封，并将可 JSON 序列化的数组交给用户传入的 publish callable。
 *
 * @example
 * $publisher = new JobPublisher(fn (array $data) => App::getQueue()->push($data));
 * $publisher->dispatch('order.paid.notify', ['orderId' => 1], ['tenantId' => 't1']);
 */
final class JobPublisher
{
    /**
     * @param callable(array<string, mixed>): void $publish
     */
    public function __construct(
        private $publish,
        private readonly JobRetryPolicy $defaultPolicy = new JobRetryPolicy(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function dispatch(string $jobType, array $payload, array $meta = []): JobEnvelope
    {
        // 组装信封后交给用户注入的 publish（如 Queue::push）
        $job = JobEnvelope::make($jobType, $payload, $meta, $this->defaultPolicy);
        ($this->publish)($job->toArray());

        return $job;
    }

    public function dispatchEnvelope(JobEnvelope $job): JobEnvelope
    {
        // 已有信封直接投递（例如重放、跨进程转发）
        ($this->publish)($job->toArray());

        return $job;
    }
}
