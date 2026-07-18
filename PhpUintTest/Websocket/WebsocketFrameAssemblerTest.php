<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use Swoole\WebSocket\Frame;
use PhpUintTest\TestCase;
use Swoolefy\Websocket\WebsocketFrameAssembler;
use Swoolefy\Websocket\WebsocketFrameException;

/**
 * WebsocketFrameAssembler 分片帧重组单元测试。
 */
final class WebsocketFrameAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WebsocketFrameAssembler::reset();
    }

    protected function tearDown(): void
    {
        WebsocketFrameAssembler::reset();
        parent::tearDown();
    }

    private function makeFrame(int $fd, int $opcode, string $data, bool $finish): Frame
    {
        $frame = new Frame();
        $frame->fd = $fd;
        $frame->opcode = $opcode;
        $frame->data = $data;
        $frame->finish = $finish;

        return $frame;
    }

    public function testSingleCompleteFrame(): void
    {
        $single = WebsocketFrameAssembler::feed($this->makeFrame(1, WEBSOCKET_OPCODE_TEXT, '{"ok":1}', true));
        $this->assertInstanceOf(Frame::class, $single);
        $this->assertSame('{"ok":1}', $single->data);
    }

    public function testTwoPartFragment(): void
    {
        $part1 = WebsocketFrameAssembler::feed($this->makeFrame(2, WEBSOCKET_OPCODE_TEXT, '{"msg":', false));
        $this->assertNull($part1);

        $part2 = WebsocketFrameAssembler::feed(
            $this->makeFrame(2, WebsocketFrameAssembler::OPCODE_CONTINUE, '"hi"}', true)
        );
        $this->assertInstanceOf(Frame::class, $part2);
        $this->assertSame('{"msg":"hi"}', $part2->data);
    }

    public function testThreePartFragment(): void
    {
        WebsocketFrameAssembler::feed($this->makeFrame(3, WEBSOCKET_OPCODE_TEXT, 'hel', false));
        WebsocketFrameAssembler::feed(
            $this->makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, 'lo', false)
        );
        $part3 = WebsocketFrameAssembler::feed(
            $this->makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, ' world', true)
        );
        $this->assertSame('hello world', $part3->data);
    }

    public function testOrphanContinuationThrows(): void
    {
        $this->expectException(WebsocketFrameException::class);
        WebsocketFrameAssembler::feed(
            $this->makeFrame(4, WebsocketFrameAssembler::OPCODE_CONTINUE, 'x', true)
        );
    }

    public function testClearOnClose(): void
    {
        WebsocketFrameAssembler::feed($this->makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'partial', false));
        WebsocketFrameAssembler::clear(5);
        $afterClear = WebsocketFrameAssembler::feed($this->makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'ok', true));
        $this->assertSame('ok', $afterClear->data);
    }
}
