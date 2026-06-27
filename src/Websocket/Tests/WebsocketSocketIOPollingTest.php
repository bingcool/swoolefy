<?php
/**
 * Socket.IO long-polling 单元测试（包批处理 / 会话队列）。
 *
 * Run: php src/Websocket/Tests/WebsocketSocketIOPollingTest.php
 */

use Swoolefy\Websocket\SocketIO\SocketIOPacket;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** encodeBatch / decodeBatch 应对 \\x1e 分隔的多包往返 */
function testBatchCodec(): void
{
    $batch = SocketIOPacket::encodeBatch(['2', '3', '42["evt",{}]']);
    assertTrue(str_contains($batch, "\x1e"), 'batch should use record separator');

    $decoded = SocketIOPacket::decodeBatch($batch);
    assertTrue($decoded === ['2', '3', '42["evt",{}]'], 'decodeBatch roundtrip');

    echo "[OK] batch codec\n";
}

/** open 包在 allow_polling 场景应可携带 websocket upgrade */
function testOpenWithUpgrades(): void
{
    $open = SocketIOPacket::open('sid-abc', 25, 20, 1000, ['websocket']);
    assertTrue(str_contains($open, '"upgrades":["websocket"]'), 'open should advertise websocket upgrade');

    echo "[OK] open with upgrades\n";
}

/** 出站队列写入后 waitOutbound 应取到包 */
function testSessionOutboundQueue(): void
{
    \Swoole\Coroutine\run(static function (): void {
        SocketIOSessionManager::resetForTest();
        $sid = 'poll-session-1';
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        SocketIOSessionManager::bindSid($sid, $virtualFd);

        assertTrue(SocketIOSessionManager::enqueueOutbound($sid, '42["chat.message",{"msg":"hi"}]'), 'enqueue ok');
        $packets = SocketIOSessionManager::waitOutbound($sid, 0);
        assertTrue(count($packets) === 1, 'should drain one packet');
        assertTrue(str_starts_with($packets[0], '42'), 'socket.io event packet');

        SocketIOSessionManager::resetForTest();
    });

    echo "[OK] session outbound queue\n";
}

/** 虚拟 fd 区间识别 */
function testVirtualFdRange(): void
{
    SocketIOSessionManager::resetForTest();
    $fd = SocketIOSessionManager::allocateVirtualFd();
    assertTrue(SocketIOSessionManager::isVirtualFd($fd), 'allocated fd should be virtual');
    assertTrue(!SocketIOSessionManager::isVirtualFd(99), 'normal fd not virtual');

    SocketIOSessionManager::resetForTest();
    echo "[OK] virtual fd range\n";
}

testBatchCodec();
testOpenWithUpgrades();
testSessionOutboundQueue();
testVirtualFdRange();

echo "All websocket socket.io polling tests passed.\n";
