<?php

declare(strict_types=1);

namespace Swoolefy\Support\Job\Tests\Fixtures;

use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobResult;

/** Job 单测 Handler：jobType=order.paid.notify。 */
final class OrderPaidNotifyHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['order.paid.notify'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $orderId = $job->payload['orderId'] ?? null;
        if (!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
            return JobResult::fail('payload.orderId required');
        }

        if (($job->payload['forceRetry'] ?? false) === true && $job->attempt < 2) {
            return JobResult::retry('demo transient failure');
        }

        return JobResult::success();
    }
}
