<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron;

use PHPUintTest\TestCase;
use Swoolefy\Worker\Cron\ExecutionResult;
use Swoolefy\Worker\Cron\KubernetesJobStatus;

final class KubernetesJobStatusTest extends TestCase
{
    public function testSucceededCountIsSuccess(): void
    {
        $this->assertSame(
            [ExecutionResult::SUCCESS, 'Job Complete'],
            KubernetesJobStatus::classify(['status' => ['succeeded' => 1]]),
        );
    }

    public function testDeadlineExceededIsTimeout(): void
    {
        $this->assertSame(
            [ExecutionResult::TIMEOUT, 'Job DeadlineExceeded DeadlineExceeded boom'],
            KubernetesJobStatus::classify([
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
        $this->assertNull(KubernetesJobStatus::classify(['status' => ['active' => 1]]));
    }
}
