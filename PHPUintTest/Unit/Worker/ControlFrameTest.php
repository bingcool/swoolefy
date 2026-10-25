<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\Runtime\ControlFrame;

/**
 * 控制帧编解码：半包、粘包、超限、EOF。
 */
final class ControlFrameTest extends TestCase
{
    public function testEncodePacksBigEndianLength(): void
    {
        $frame = ControlFrame::encode('{"a":1}');

        $this->assertSame(pack('N', 7) . '{"a":1}', $frame);
    }

    public function testEncodeRejectsEmptyPayload(): void
    {
        $this->expectException(WorkerException::class);
        ControlFrame::encode('');
    }

    public function testEncodeRejectsOversizePayload(): void
    {
        $this->expectException(WorkerException::class);
        ControlFrame::encode(str_repeat('a', 17), 16);
    }

    public function testTryReadHalfPacketThenComplete(): void
    {
        [$writer, $reader] = $this->socketPair();
        $frame = ControlFrame::encode('hello');

        fwrite($writer, substr($frame, 0, 2));
        $buffer = '';
        $this->assertNull(ControlFrame::tryRead($reader, $buffer));

        fwrite($writer, substr($frame, 2));
        $this->assertSame('hello', ControlFrame::tryRead($reader, $buffer));
        $this->assertSame('', $buffer);
    }

    public function testTryReadCoalescedFramesFromBuffer(): void
    {
        [$writer, $reader] = $this->socketPair();
        fwrite($writer, ControlFrame::encode('one') . ControlFrame::encode('two'));

        $buffer = '';
        $this->assertSame('one', ControlFrame::tryRead($reader, $buffer));

        // 第二帧已在缓冲里，不应再阻塞读
        $this->assertSame('two', ControlFrame::tryRead($reader, $buffer));
        $this->assertSame('', $buffer);
    }

    public function testTryReadReturnsFalseOnEof(): void
    {
        [$writer, $reader] = $this->socketPair();
        fclose($writer);

        $buffer = '';
        $this->assertFalse(ControlFrame::tryRead($reader, $buffer));
    }

    public function testTryReadRejectsOversizeHeader(): void
    {
        [$writer, $reader] = $this->socketPair();
        fwrite($writer, pack('N', 2048));

        $buffer = '';
        $this->expectException(WorkerException::class);
        ControlFrame::tryRead($reader, $buffer, 1024);
    }

    /**
     * @return array{0:resource,1:resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        stream_set_blocking($pair[1], false);

        return $pair;
    }
}
