<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Dto\ControlRequest;
use Swoolefy\Worker\Dto\ControlResponse;

/**
 * 控制请求/响应值对象的 JSON 契约。
 */
final class ControlMessageTest extends TestCase
{
    public function testRequestRoundTrip(): void
    {
        $request = new ControlRequest(WORKER_CLI_SEND_MSG, 'proc-1', WORKER_CLI_START, 'raw-msg', 'n-1');
        $decoded = ControlRequest::fromJson($request->toJson());

        $this->assertSame(WORKER_CLI_SEND_MSG, $decoded->action);
        $this->assertSame('proc-1', $decoded->target);
        $this->assertSame(WORKER_CLI_START, $decoded->op);
        $this->assertSame('raw-msg', $decoded->message);
        $this->assertSame('n-1', $decoded->nonce);
        $this->assertSame(ControlRequest::VERSION, $decoded->version);
    }

    public function testRequestJsonUsesParamsObject(): void
    {
        $request = new ControlRequest(WORKER_CLI_STATUS, '', '', '', 'n-2');
        $decoded = json_decode($request->toJson(), true);

        $this->assertSame(1, $decoded['v']);
        $this->assertSame(['op' => '', 'msg' => ''], $decoded['params']);
        $this->assertArrayNotHasKey('message', $decoded);
    }

    public function testRequestRejectsUnknownAction(): void
    {
        $this->expectException(WorkerException::class);
        ControlRequest::fromArray(['v' => 1, 'action' => 'drop-database']);
    }

    public function testRequestRejectsUnsupportedVersion(): void
    {
        $this->expectException(WorkerException::class);
        ControlRequest::fromArray(['v' => 99, 'action' => WORKER_CLI_STATUS]);
    }

    public function testRequestRejectsInvalidJson(): void
    {
        $this->expectException(WorkerException::class);
        ControlRequest::fromJson('{not-json');
    }

    public function testResponseRoundTrip(): void
    {
        $response = ControlResponse::failure('boom', 'n-3');
        $decoded = ControlResponse::fromJson($response->toJson());

        $this->assertFalse($decoded->ok);
        $this->assertSame('boom', $decoded->message);
        $this->assertSame('', $decoded->data);
        $this->assertSame('n-3', $decoded->nonce);
    }
}
