<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Kubernetes\JobStatus;

final class KubernetesJobStatusTest extends TestCase
{
    public function testSucceededCountIsSuccess(): void
    {
        $this->assertSame(
            [JobStatus::COMPLETE, 'Job Complete'],
            JobStatus::classify(['status' => ['succeeded' => 1]]),
        );
    }

    public function testDeadlineExceededIsTimeout(): void
    {
        $this->assertSame(
            [JobStatus::DEADLINE_EXCEEDED, 'Job DeadlineExceeded DeadlineExceeded boom'],
            JobStatus::classify([
                'status' => [
                    'conditions' => [[
                        'type' => 'Failed',
                        'status' => 'True',
                        'reason' => 'DeadlineExceeded',
                        'message' => 'boom',
                    ]],
                ],
            ]),
        );
    }

    public function testActiveJobIsNull(): void
    {
        $this->assertNull(JobStatus::classify(['status' => ['active' => 1]]));
    }
}
