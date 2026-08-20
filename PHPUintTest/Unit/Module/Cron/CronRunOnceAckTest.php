<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\TestCase;
use Test\Module\Cron\Service\CronTaskManagerService;
use Test\Module\Cron\Service\CronTaskService;

/**
 * P0-1：RunOnce 必须按 request 主键逐条 ack，fetcher 必须带上 requestId 列表。
 */
final class CronRunOnceAckTest extends TestCase
{
    public function testAckRunOnceConsumesByRequestIdNotCronId(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );
        $this->assertTrue(
            (bool) preg_match('/public function ackRunOnce\(int \$requestId\): void\s*\{(.*?)^\s{4}\}/ms', $src, $m),
            'ackRunOnce 方法必须存在',
        );
        $body = $m[1];
        $this->assertStringContainsString("where('id', \$requestId)", $body, 'ackRunOnce 必须按 request 主键消费');
        $this->assertStringNotContainsString('cron_id', $body, 'ackRunOnce 不得按 cron_id 清空全部 pending');
        $this->assertStringContainsString('function findOnePendingRunOnce(int $cronTaskId)', $src);
        $this->assertStringContainsString('function listPendingRunOnceIds(int $cronTaskId)', $src);
    }

    public function testFetcherAttachesPendingRequestIds(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskService::class))->getFileName()
        );
        $this->assertStringContainsString("listPendingRunOnceIds", $src);
        $this->assertStringContainsString("run_once_request_ids", $src);
        $this->assertStringContainsString("run_once_request_id", $src);
    }

    public function testWorkerConfAcksByRequestId(): void
    {
        $root = dirname(__DIR__, 4);
        $fork = (string) file_get_contents($root . '/Test/WorkerCron/conf/schedule_fork_conf.php');
        $url = (string) file_get_contents($root . '/Test/WorkerCron/conf/schedule_url_conf.php');
        $this->assertStringContainsString('ackRunOnce($requestId)', $fork);
        $this->assertStringContainsString('ackRunOnce($requestId)', $url);
    }
}
