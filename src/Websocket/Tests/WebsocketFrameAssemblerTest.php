<?php
/**
 * WebsocketFrameAssembler unit tests.
 *
 * Run: php src/Websocket/Tests/WebsocketFrameAssemblerTest.php
 */

use Swoole\WebSocket\Frame;
use Swoolefy\Websocket\WebsocketFrameAssembler;
use Swoolefy\Websocket\WebsocketFrameException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeFrame(int $fd, int $opcode, string $data, bool $finish): Frame
{
    $frame = new Frame();
    $frame->fd = $fd;
    $frame->opcode = $opcode;
    $frame->data = $data;
    $frame->finish = $finish;

    return $frame;
}

WebsocketFrameAssembler::reset();

// 单帧完整消息
$single = WebsocketFrameAssembler::feed(makeFrame(1, WEBSOCKET_OPCODE_TEXT, '{"ok":1}', true));
assertTrue($single instanceof Frame, 'single frame should return frame');
assertTrue($single->data === '{"ok":1}', 'single frame data mismatch');

// 两帧分片
$part1 = WebsocketFrameAssembler::feed(makeFrame(2, WEBSOCKET_OPCODE_TEXT, '{"msg":', false));
assertTrue($part1 === null, 'first fragment should wait');
$part2 = WebsocketFrameAssembler::feed(makeFrame(2, WebsocketFrameAssembler::OPCODE_CONTINUE, '"hi"}', true));
assertTrue($part2 instanceof Frame, 'second fragment should complete');
assertTrue($part2->data === '{"msg":"hi"}', 'merged fragment data mismatch');

// 三帧分片
WebsocketFrameAssembler::feed(makeFrame(3, WEBSOCKET_OPCODE_TEXT, 'hel', false));
WebsocketFrameAssembler::feed(makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, 'lo', false));
$part3 = WebsocketFrameAssembler::feed(makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, ' world', true));
assertTrue($part3->data === 'hello world', 'three-part merge failed');

// 孤立 continuation 应报错
try {
    WebsocketFrameAssembler::feed(makeFrame(4, WebsocketFrameAssembler::OPCODE_CONTINUE, 'x', true));
    throw new RuntimeException('expected WebsocketFrameException');
} catch (WebsocketFrameException $e) {
    assertTrue(true, '');
}

// close 清理缓存
WebsocketFrameAssembler::feed(makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'partial', false));
WebsocketFrameAssembler::clear(5);
$afterClear = WebsocketFrameAssembler::feed(makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'ok', true));
assertTrue($afterClear->data === 'ok', 'buffer should be cleared on close');

echo "All websocket frame assembler tests passed.\n";
