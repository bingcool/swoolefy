<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\Infrastructure\ControlClient;
use Swoolefy\Worker\Dto\ControlRequest;

/**
 * ControlClient 传输失败必须返回可打印的失败结果，不能抛到 CLI 顶层层。
 */
final class ControlClientTest extends TestCase
{
    public function testEmptySocketPathFails(): void
    {
        $response = (new ControlClient())->request('', new ControlRequest(WORKER_CLI_STATUS));

        $this->assertFalse($response->ok);
        $this->assertStringContainsString('路径为空', $response->message);
    }

    public function testMissingSocketFailsWithReason(): void
    {
        $socketPath = '/tmp/sfc_missing_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sock';
        $response = (new ControlClient())->request($socketPath, new ControlRequest(WORKER_CLI_STATUS));

        $this->assertFalse($response->ok);
        $this->assertStringContainsString('连接 Worker 控制面失败', $response->message);
        $this->assertFileDoesNotExist($socketPath);
    }
}
