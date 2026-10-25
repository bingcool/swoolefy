<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\Dto\ControlResponse;
use Swoolefy\Worker\Runtime\ControlFrame;
use Swoolefy\Worker\Runtime\ReplySink;

/**
 * 回包对象：一个请求恰好一帧；respondNow 直写后立即关闭。
 */
final class ReplySinkTest extends TestCase
{
    public function testOnlyFirstResponseIsKept(): void
    {
        $sink = new ReplySink();

        $sink->respondText('first');
        $sink->respondText('second');

        $this->assertTrue($sink->hasResponded());
        $this->assertCount(1, $sink->sentFrames());

        $payload = json_decode(substr($sink->sentFrames()[0], 4), true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('first', $payload['message']);
    }

    public function testNonceIsEchoed(): void
    {
        $sink = new ReplySink();
        $sink->setNonce('nonce-42');
        $sink->respondData('table-text', 'look');

        $payload = json_decode(substr($sink->sentFrames()[0], 4), true);
        $this->assertSame('nonce-42', $payload['nonce']);
        $this->assertSame('table-text', $payload['data']);
        $this->assertSame('look', $payload['message']);
    }

    public function testRespondNowClosesImmediately(): void
    {
        [$writer, $reader] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $sink = new ReplySink($writer);

        $sink->respondNow('bye');

        $this->assertTrue($sink->isClosed());
        $this->assertTrue($sink->hasResponded());

        $buffer = '';
        $payload = ControlFrame::tryRead($reader, $buffer, ControlFrame::MAX_RESPONSE_BYTES);
        $this->assertSame('bye', json_decode($payload, true)['message']);
    }

    public function testOversizeResponseFallsBackToFailureFrame(): void
    {
        $sink = new ReplySink();
        $sink->respondData(str_repeat('x', ControlFrame::MAX_RESPONSE_BYTES + 1));

        $payload = json_decode(substr($sink->sentFrames()[0], 4), true);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('过大', $payload['message']);
    }

    public function testRespondAfterCloseIsIgnored(): void
    {
        $sink = new ReplySink();
        $sink->close();
        $sink->respond(ControlResponse::success('late'));

        $this->assertCount(0, $sink->sentFrames());
        $this->assertFalse($sink->hasResponded());
    }
}
