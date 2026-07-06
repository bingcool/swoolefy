<?php
/**
 * WebsocketFrameAssembler 分片帧重组单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketFrameAssemblerTest.php
 */

use Swoole\WebSocket\Frame;
use Swoolefy\Websocket\WebsocketFrameAssembler;
use Swoolefy\Websocket\WebsocketFrameException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 构造测试用 WebSocket Frame */
function makeFrame(int $fd, int $opcode, string $data, bool $finish): Frame
{
    $frame = new Frame();
    $frame->fd = $fd;
    $frame->opcode = $opcode;
    $frame->data = $data;
    $frame->finish = $finish;

    return $frame;
}

/** finish=true 的单帧应直接返回，无需缓存 */
function testSingleCompleteFrame(): void
{
    WebsocketFrameAssembler::reset();

    $single = WebsocketFrameAssembler::feed(makeFrame(1, WEBSOCKET_OPCODE_TEXT, '{"ok":1}', true));
    assertTrue($single instanceof Frame, 'single frame should return frame');
    assertTrue($single->data === '{"ok":1}', 'single frame data mismatch');
    echo "[OK] single complete frame\n";
}

/** 两帧分片（TEXT + CONTINUE）应合并为一条完整消息 */
function testTwoPartFragment(): void
{
    WebsocketFrameAssembler::reset();

    $part1 = WebsocketFrameAssembler::feed(makeFrame(2, WEBSOCKET_OPCODE_TEXT, '{"msg":', false));
    assertTrue($part1 === null, 'first fragment should wait');
    $part2 = WebsocketFrameAssembler::feed(makeFrame(2, WebsocketFrameAssembler::OPCODE_CONTINUE, '"hi"}', true));
    assertTrue($part2 instanceof Frame, 'second fragment should complete');
    assertTrue($part2->data === '{"msg":"hi"}', 'merged fragment data mismatch');
    echo "[OK] two-part fragment\n";
}

/** 三帧连续分片应正确拼接 */
function testThreePartFragment(): void
{
    WebsocketFrameAssembler::reset();

    WebsocketFrameAssembler::feed(makeFrame(3, WEBSOCKET_OPCODE_TEXT, 'hel', false));
    WebsocketFrameAssembler::feed(makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, 'lo', false));
    $part3 = WebsocketFrameAssembler::feed(makeFrame(3, WebsocketFrameAssembler::OPCODE_CONTINUE, ' world', true));
    assertTrue($part3->data === 'hello world', 'three-part merge failed');
    echo "[OK] three-part fragment\n";
}

/** 无起始帧的孤立 continuation 应抛出 WebsocketFrameException */
function testOrphanContinuationThrows(): void
{
    WebsocketFrameAssembler::reset();

    try {
        WebsocketFrameAssembler::feed(makeFrame(4, WebsocketFrameAssembler::OPCODE_CONTINUE, 'x', true));
        throw new RuntimeException('expected WebsocketFrameException');
    } catch (WebsocketFrameException $e) {
        assertTrue(true, '');
    }
    echo "[OK] orphan continuation throws\n";
}

/** 连接 close 时 clear(fd) 应丢弃未完成的分片缓存 */
function testClearOnClose(): void
{
    WebsocketFrameAssembler::reset();

    WebsocketFrameAssembler::feed(makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'partial', false));
    WebsocketFrameAssembler::clear(5);
    $afterClear = WebsocketFrameAssembler::feed(makeFrame(5, WEBSOCKET_OPCODE_TEXT, 'ok', true));
    assertTrue($afterClear->data === 'ok', 'buffer should be cleared on close');
    echo "[OK] clear on close\n";
}

testSingleCompleteFrame();
testTwoPartFragment();
testThreePartFragment();
testOrphanContinuationThrows();
testClearOnClose();

echo "All websocket frame assembler tests passed.\n";
