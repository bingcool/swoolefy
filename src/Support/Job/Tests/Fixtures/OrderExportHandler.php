<?php

declare(strict_types=1);

namespace Swoolefy\Support\Job\Tests\Fixtures;

use Swoolefy\Support\Job\JobEnvelope;
use Swoolefy\Support\Job\JobHandlerInterface;
use Swoolefy\Support\Job\JobResult;

/** Job 单测 Handler：jobType=order.export。 */
final class OrderExportHandler implements JobHandlerInterface
{
    public function types(): array
    {
        return ['order.export'];
    }

    public function handle(JobEnvelope $job): JobResult
    {
        $orderId = $job->payload['orderId'] ?? null;
        if ($orderId === null || $orderId === '') {
            return JobResult::fail('payload.orderId required');
        }

        return JobResult::success();
    }
}
