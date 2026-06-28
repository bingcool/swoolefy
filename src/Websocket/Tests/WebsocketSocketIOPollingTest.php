<?php
/**
 * Socket.IO long-polling 单元测试（包批处理 / 会话队列）。
 *
 * Run: php src/Websocket/Tests/WebsocketSocketIOPollingTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingConfig;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingOutboundStore;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

define('APP_NAME', 'WebsocketService');
define('APP_PATH', $root . '/WebsocketService');
define('WORKER_PORT', 9508);

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

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
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
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
    SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
    $fd = SocketIOSessionManager::allocateVirtualFd();
    assertTrue(SocketIOSessionManager::isVirtualFd($fd), 'allocated fd should be virtual');
    assertTrue(!SocketIOSessionManager::isVirtualFd(99), 'normal fd not virtual');

    SocketIOSessionManager::resetForTest();
    echo "[OK] virtual fd range\n";
}

/** shared_store 配置解析 */
function testSharedStoreConfig(): void
{
    SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    ClusterConfig::setWebsocketOverride([
        'cluster' => ['enable' => true],
        'socketio' => ['polling' => ['shared_store' => 'auto']],
    ]);
    assertTrue(SocketIOPollingConfig::usesSharedStore(), 'auto + cluster should use shared store');

    ClusterConfig::setWebsocketOverride([
        'cluster' => ['enable' => false],
        'socketio' => ['polling' => ['shared_store' => 'memory']],
    ]);
    SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
    assertTrue(!SocketIOPollingConfig::usesSharedStore(), 'memory override should disable shared store');

    ClusterConfig::setWebsocketOverride(null);
    SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    echo "[OK] shared store config\n";
}

/** Redis 出站队列（需本机 Redis，与 WebsocketClusterTest 相同条件） */
function testSharedOutboundQueue(): void
{
    try {
        ClusterRedisClient::execute(static fn ($redis) => $redis->ping());
    } catch (\Throwable $throwable) {
        echo "[SKIP] shared outbound queue (redis unavailable)\n";

        return;
    }

    ClusterConfig::setWebsocketOverride([
        'cluster' => [
            'enable' => true,
            'redis' => ClusterConfig::redis(),
        ],
        'socketio' => ['polling' => ['shared_store' => 'redis']],
    ]);
    SocketIOPollingConfig::setSharedStoreOverrideForTest(null);

    $sid = 'poll-redis-' . bin2hex(random_bytes(4));
    try {
        assertTrue(SocketIOPollingOutboundStore::enqueue($sid, '42["ping",{}]'), 'redis enqueue');
        $packets = SocketIOPollingOutboundStore::drain($sid);
        assertTrue(count($packets) === 1 && str_starts_with($packets[0], '42'), 'redis drain');
    } finally {
        SocketIOPollingOutboundStore::clear($sid);
        ClusterRedisClient::resetSharedAdapter();
        ClusterConfig::setWebsocketOverride(null);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    }

    echo "[OK] shared outbound queue\n";
}

testBatchCodec();
testOpenWithUpgrades();
testSessionOutboundQueue();
testVirtualFdRange();
testSharedStoreConfig();
testSharedOutboundQueue();

echo "All websocket socket.io polling tests passed.\n";
