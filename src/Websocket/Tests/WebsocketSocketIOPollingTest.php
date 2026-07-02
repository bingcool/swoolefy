<?php
/**
 * Socket.IO long-polling 单元测试（包批处理 / 会话队列）。
 *
 * Run: php src/Websocket/Tests/WebsocketSocketIOPollingTest.php
 */

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingConfig;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingOutboundStore;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingSessionRegistry;
use Swoolefy\Websocket\SocketIO\SocketIOHandler;
use Swoolefy\Websocket\SocketIO\SocketIONamespaceRegistry;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;
use Swoolefy\Websocket\WebsocketConnectionManager;

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

/** polling GET 空响应必须是 noop，不能是空 body */
function testPollingEmptyPayloadNoop(): void
{
    assertTrue(SocketIOPacket::encodeBatch([]) === '', 'generic batch empty remains empty');
    assertTrue(SocketIOPacket::encodePollingPayload([]) === SocketIOPacket::ENGINE_NOOP, 'polling empty payload should be noop');
    assertTrue(SocketIOPacket::encodePollingPayload(['40{"sid":"abc"}']) === '40{"sid":"abc"}', 'polling non-empty payload');

    echo "[OK] polling empty payload noop\n";
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

/** 单 sid 单 waiter：第二条 waitOutbound 立即空，不阻塞 */
function testSingleSidWaiter(): void
{
    SocketIOSessionManager::resetForTest();
    SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
    $sid = 'poll-waiter-1';
    SocketIOSessionManager::bindSid($sid, SocketIOSessionManager::allocateVirtualFd());

    $first = null;
    $second = null;
    \Swoole\Coroutine\run(static function () use ($sid, &$first, &$second): void {
        \Swoole\Coroutine::create(static function () use ($sid, &$first): void {
            $first = SocketIOSessionManager::waitOutbound($sid, 2);
        });
        \Swoole\Coroutine::sleep(0.05);
        $second = SocketIOSessionManager::waitOutbound($sid, 2);
    });

    assertTrue($first === [], 'first waiter should timeout empty');
    assertTrue($second === [], 'second waiter should return immediately without blocking');

    SocketIOSessionManager::resetForTest();
    echo "[OK] single sid waiter\n";
}

/** short_poll_wait_sec 配置解析 */
function testShortPollWaitConfig(): void
{
    ClusterConfig::setWebsocketOverride([
        'socketio' => ['polling' => ['short_poll_wait_sec' => 3]],
    ]);
    assertTrue(SocketIOPollingConfig::shortPollWaitSec(25) === 3, 'short poll wait');

    ClusterConfig::setWebsocketOverride([
        'socketio' => ['polling' => ['short_poll_wait_sec' => 99]],
    ]);
    assertTrue(SocketIOPollingConfig::shortPollWaitSec(25) === 5, 'short poll wait capped at 5');

    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] short poll wait config\n";
}

/** connect ack 识别 */
function testConnectAckDetect(): void
{
    $ack = SocketIOPacket::connectAck('sid-xyz');
    assertTrue(SocketIOPacket::isConnectAck($ack), 'connect ack');
    assertTrue(!SocketIOPacket::isConnectAck('42["evt",{}]'), 'event not connect ack');

    $chatAck = SocketIOPacket::connectAck('sid-xyz', '/chat');
    assertTrue(SocketIOPacket::isConnectAck($chatAck), 'chat namespace connect ack');
    assertTrue(str_contains($chatAck, '/chat,'), 'chat namespace prefix in ack');

    echo "[OK] connect ack detect\n";
}

/** polling POST `40/chat,` 注册 namespace 并返回 connect ack */
function testNamespaceConnectViaPolling(): void
{
    SocketIOSessionManager::resetForTest();
    SocketIONamespaceRegistry::resetForTest();
    SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
    SocketIONamespaceRegistry::setConfigOverride([
        'allowed_namespaces' => ['*'],
    ]);

    $virtualFd = 1073742200;
    $sid = 'ns-poll-sid';
    registerPollingConnection($virtualFd, $sid);

    $config = ['socketio' => ['enable' => true, 'allowed_namespaces' => ['*']]];
    $outbound = SocketIOHandler::handleInboundPollingBatch($virtualFd, '40/chat,', $config);

    assertTrue(count($outbound) === 1, 'one connect ack packet');
    assertTrue(SocketIOPacket::isConnectAck($outbound[0]), 'connect ack');
    assertTrue(str_contains($outbound[0], '/chat,'), 'ack contains namespace prefix');

    $connection = WebsocketConnectionManager::getConnection($virtualFd);
    assertTrue(str_contains((string) ($connection['socketio_namespaces'] ?? ''), '/chat'), 'namespace registered');
    assertTrue(SocketIONamespaceRegistry::isConnected($virtualFd, '/chat'), 'isConnected /chat');

    SocketIONamespaceRegistry::setConfigOverride([
        'allowed_namespaces' => ['/', '/chat'],
    ]);
    $virtualFd2 = 1073742201;
    registerPollingConnection($virtualFd2, 'ns-deny-sid');
    $denied = SocketIOHandler::handleInboundPollingBatch($virtualFd2, '40/admin,', $config);
    assertTrue(count($denied) === 1, 'denied namespace returns one packet');
    assertTrue(str_contains($denied[0], 'namespace not allowed'), 'admin namespace denied');

    SocketIONamespaceRegistry::resetForTest();
    SocketIOSessionManager::resetForTest();
    SocketIOPollingConfig::setSharedStoreOverrideForTest(null);

    echo "[OK] namespace connect via polling\n";
}

/** 单测：创建 polling 回归所需的 Swoole Table */
function setupPollingRegressionTables(): void
{
    if (TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
        return;
    }

    TableManager::createTable(WebsocketConnectionManager::tableDefinitions([
        'connection_table_size' => 1024,
        'index_table_size' => 2048,
        'socketio' => ['allow_polling' => true],
    ]));
}

/** 单测：在连接表注册 polling 虚拟 fd（绕过 openPolling 的集群副作用） */
function registerPollingConnection(int $virtualFd, string $sid, string $userId = 'poll-test-user'): void
{
    setupPollingRegressionTables();
    TableManager::set(WebsocketConnectionManager::TABLE_CONNECTIONS, (string) $virtualFd, [
        'fd' => $virtualFd,
        'worker_id' => 0,
        'user_id' => $userId,
        'sid' => $sid,
        'groups' => '',
        'remote_addr' => '127.0.0.1',
        'user_agent' => 'polling-regression-test',
        'connected_at' => time(),
        'last_active_at' => time(),
        'is_socketio' => 1,
        'is_polling' => 1,
        'socketio_namespaces' => '',
        'conn_id' => 'test:' . $virtualFd,
    ]);
}

/** 单测：模拟 handlePost 处理 POST `40`（POST 返回 ok，下行 ack 入队给 GET） */
function simulatePostConnect40(int $virtualFd, string $sid, array $config): string
{
    $outbound = SocketIOHandler::handleInboundPollingBatch($virtualFd, '40', $config);
    foreach ($outbound as $packet) {
        SocketIOSessionManager::enqueueOutbound($sid, $packet);
    }

    return 'ok';
}

/** @return string[] */
function connectAcksFromPackets(array $packets): array
{
    return array_values(array_filter($packets, static fn (string $packet): bool => SocketIOPacket::isConnectAck($packet)));
}

/**
 * 并发双 GET + POST 40 + connect ack 到达顺序（engine.io-client 最易复现的回归场景）。
 *
 * 期望时序：
 * 1. GET #1 成为唯一 waiter，短阻塞等待
 * 2. GET #2 未获锁，业务层立即空包；HTTP 响应层会编码为 noop `6`
 * 3. POST `40` 返回 `ok`，同时入队 connect ack
 * 4. GET #1 返回 `40{sid}`，GET #2 为空；后续心跳由周期 ping 负责
 */
function testDualGetPostConnectAckRegression(bool $useSharedStore): void
{
    $label = $useSharedStore ? 'redis' : 'memory';

    if ($useSharedStore) {
        try {
            ClusterRedisClient::execute(static fn ($redis) => $redis->ping());
        } catch (\Throwable $throwable) {
            echo "[SKIP] dual get post connect ack regression (redis unavailable)\n";

            return;
        }
    }

    \Swoole\Coroutine\run(static function () use ($useSharedStore, $label): void {
        SocketIOSessionManager::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest($useSharedStore ? null : false);

        if ($useSharedStore) {
            ClusterConfig::setWebsocketOverride([
                'cluster' => [
                    'enable' => true,
                    'server_id' => 'ws-poll-regression',
                    'redis' => ClusterConfig::redis(),
                ],
                'socketio' => [
                    'poll_timeout' => 25,
                    'allowed_namespaces' => ['*'],
                    'polling' => [
                        'shared_store' => 'redis',
                        'short_poll_wait_sec' => 2,
                    ],
                ],
            ]);
            ClusterNodeIdentity::reset();
        } else {
            ClusterConfig::setWebsocketOverride([
                'socketio' => [
                    'poll_timeout' => 25,
                    'allowed_namespaces' => ['*'],
                    'polling' => ['short_poll_wait_sec' => 2],
                ],
            ]);
        }

        $config = ClusterConfig::websocket()['socketio'] ?? [];
        $config = ['socketio' => is_array($config) ? $config : []];

        $sid = 'poll-dual-' . ($useSharedStore ? 'redis-' : 'mem-') . bin2hex(random_bytes(3));
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        SocketIOSessionManager::bindSid($sid, $virtualFd);
        registerPollingConnection($virtualFd, $sid);

        $pollTimeout = SocketIOHandler::pollTimeout($config);
        $get1Packets = [];
        $get2Packets = [];
        $postBody = '';
        $get1Elapsed = 0.0;
        $get2Elapsed = 0.0;
        $postElapsed = 0.0;
        $tGet2Done = 0.0;
        $tPostDone = 0.0;
        $tGet1Done = 0.0;

        $get1Started = new \Swoole\Coroutine\Channel(1);
        $get2Done = new \Swoole\Coroutine\Channel(1);
        $get1Done = new \Swoole\Coroutine\Channel(1);

        // GET #1：engine.io-client 并发 long-poll 之一
        \Swoole\Coroutine::create(static function () use (
            $sid,
            $pollTimeout,
            $get1Started,
            $get1Done,
            &$get1Packets,
            &$get1Elapsed,
            &$tGet1Done
        ): void {
            $get1Started->push(true);
            $startedAt = microtime(true);
            $get1Packets = SocketIOSessionManager::waitOutbound($sid, $pollTimeout);
            $get1Elapsed = microtime(true) - $startedAt;
            $tGet1Done = microtime(true);
            $get1Done->push(true);
        });

        assertTrue((bool) $get1Started->pop(1.0), "{$label}: GET #1 should start");
        \Swoole\Coroutine::sleep(0.05);

        // GET #2：第二条并发 GET，应立刻空返回
        $get2StartedAt = microtime(true);
        \Swoole\Coroutine::create(static function () use (
            $sid,
            $pollTimeout,
            $get2Done,
            &$get2Packets,
            &$get2Elapsed,
            &$tGet2Done,
            $get2StartedAt
        ): void {
            $get2Packets = SocketIOSessionManager::waitOutbound($sid, $pollTimeout);
            $get2Elapsed = microtime(true) - $get2StartedAt;
            $tGet2Done = microtime(true);
            $get2Done->push(true);
        });

        assertTrue((bool) $get2Done->pop(1.0), "{$label}: GET #2 should finish quickly");
        assertTrue($get2Elapsed < 0.25, "{$label}: GET #2 should not block on short poll wait");
        assertTrue($get2Packets === [], "{$label}: GET #2 should be immediate empty poll");

        // POST `40`：connect 不应被双 GET 占死
        $postStartedAt = microtime(true);
        $postBody = simulatePostConnect40($virtualFd, $sid, $config);
        $postElapsed = microtime(true) - $postStartedAt;
        $tPostDone = microtime(true);

        assertTrue((bool) $get1Done->pop(3.0), "{$label}: GET #1 should receive ack after POST");
        assertTrue($postElapsed < 0.5, "{$label}: POST 40 should not be blocked by dual GET");

        $get1Acks = connectAcksFromPackets($get1Packets);
        $get2Acks = connectAcksFromPackets($get2Packets);

        assertTrue($postBody === 'ok', "{$label}: POST should return Engine.IO ok");
        assertTrue(count($get1Acks) === 1, "{$label}: GET #1 should carry one connect ack");
        assertTrue($get2Acks === [], "{$label}: GET #2 must not carry connect ack");
        assertTrue(!in_array(SocketIOPacket::ENGINE_PING, $get1Packets, true), "{$label}: GET #1 should not carry ping before connect ack settles");
        assertTrue(str_contains($get1Acks[0], $sid), "{$label}: connect ack should contain session sid");

        // 到达顺序：GET #2 先结束 → POST 完成 → GET #1 收到 ack
        assertTrue($tGet2Done > 0 && $tGet2Done <= $tPostDone + 0.05, "{$label}: GET #2 should finish before or with POST");
        assertTrue($tPostDone <= $tGet1Done + 0.05, "{$label}: GET #1 ack should arrive right after POST enqueue");
        assertTrue($get1Elapsed >= 0.01, "{$label}: GET #1 should have waited for POST, not instant drain");

        SocketIOSessionManager::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        if ($useSharedStore) {
            SocketIOPollingOutboundStore::clear($sid);
            ClusterRedisClient::resetSharedAdapter();
            ClusterNodeIdentity::reset();
        }
        ClusterConfig::setWebsocketOverride(null);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    });

    echo "[OK] dual get post connect ack regression ({$label})\n";
}

/** memory 模式出站队列应 respect outbound_max_len */
function testMemoryOutboundMaxLen(): void
{
    \Swoole\Coroutine\run(static function (): void {
        SocketIOSessionManager::resetForTest();
        ClusterConfig::setWebsocketOverride([
            'socketio' => ['polling' => ['outbound_max_len' => 16]],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);

        $sid = 'mem-max-len';
        for ($i = 1; $i <= 20; $i++) {
            SocketIOPollingOutboundStore::enqueue($sid, 'p' . $i);
        }

        $packets = SocketIOPollingOutboundStore::drain($sid);
        assertTrue(count($packets) === 16, 'memory queue should trim to max len');
        assertTrue($packets[0] === 'p5' && $packets[15] === 'p20', 'memory queue should keep newest packets');

        ClusterConfig::setWebsocketOverride(null);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        SocketIOSessionManager::resetForTest();
    });

    echo "[OK] memory outbound max len\n";
}

/** 跨节点 poll：Redis 有 sid、本机 Table 无映射时 ensureLocal 回填 */
function testEnsureLocalFromRedis(): void
{
    try {
        ClusterRedisClient::execute(static fn ($redis) => $redis->ping());
    } catch (\Throwable $throwable) {
        echo "[SKIP] ensure local from redis (redis unavailable)\n";

        return;
    }

    setupPollingRegressionTables();
    SocketIOSessionManager::resetForTest();
    SocketIONamespaceRegistry::resetForTest();

    ClusterConfig::setWebsocketOverride([
        'cluster' => [
            'enable' => true,
            'server_id' => 'ws-poll-node-a',
            'redis' => ClusterConfig::redis(),
        ],
        'socketio' => [
            'allowed_namespaces' => ['*'],
            'polling' => ['shared_store' => 'redis'],
        ],
    ]);
    ClusterNodeIdentity::reset();
    SocketIOPollingConfig::setSharedStoreOverrideForTest(null);

    $sid = 'poll-cross-' . bin2hex(random_bytes(4));
    $virtualFd = 1073742100;
    $connId = 'ws-poll-node-a:' . $virtualFd;
    $config = ['socketio' => ClusterConfig::websocket()['socketio'] ?? []];

    try {
        ClusterRedisClient::execute(static function ($redis) use ($sid, $virtualFd, $connId): void {
            $key = SocketIOPollingConfig::redisKeyPrefix() . 'poll:sid:' . $sid;
            $now = (string) time();
            $redis->hMSet($key, [
                'sid' => $sid,
                'virtual_fd' => (string) $virtualFd,
                'server_id' => 'ws-poll-node-a',
                'conn_id' => $connId,
                'user_id' => 'cross-node-user',
                'created_at' => $now,
                'last_active_at' => $now,
            ]);
            $redis->expire($key, SocketIOPollingConfig::sessionTtl());
        });

        assertTrue(!TableManager::exist(SocketIOPollingSessionRegistry::TABLE_POLLING_SID, $sid), 'local table should miss sid');

        $resolved = SocketIOSessionManager::resolveSession($sid);
        assertTrue($resolved === $virtualFd, 'resolveSession should hydrate from redis');
        assertTrue(TableManager::exist(SocketIOPollingSessionRegistry::TABLE_POLLING_SID, $sid), 'local table should be hydrated');

        $connection = WebsocketConnectionManager::getConnection($virtualFd);
        assertTrue(is_array($connection) && (int) ($connection['is_polling'] ?? 0) === 1, 'shadow polling connection');
        assertTrue(($connection['user_id'] ?? '') === 'cross-node-user', 'shadow connection user_id');

        $postOutbound = simulatePostConnect40($virtualFd, $sid, $config);
        assertTrue(connectAcksFromPackets($postOutbound) !== [], 'cross-node shadow should handle POST 40');
    } finally {
        SocketIOPollingSessionRegistry::remove($sid);
        if (TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
            TableManager::del(WebsocketConnectionManager::TABLE_CONNECTIONS, (string) $virtualFd);
        }
        SocketIOSessionManager::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        ClusterRedisClient::resetSharedAdapter();
        ClusterNodeIdentity::reset();
        ClusterConfig::setWebsocketOverride(null);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    }

    echo "[OK] ensure local from redis\n";
}

/** session_touch_interval 配置解析 */
function testSessionTouchIntervalConfig(): void
{
    ClusterConfig::setWebsocketOverride([
        'cluster' => ['touch_interval' => 20],
        'socketio' => ['polling' => ['session_touch_interval' => 12]],
    ]);
    assertTrue(SocketIOPollingConfig::sessionTouchInterval() === 12, 'polling touch interval override');

    ClusterConfig::setWebsocketOverride([
        'cluster' => ['touch_interval' => 20],
        'socketio' => ['polling' => []],
    ]);
    assertTrue(SocketIOPollingConfig::sessionTouchInterval() === 20, 'fallback cluster touch interval');

    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] session touch interval config\n";
}

testBatchCodec();
testPollingEmptyPayloadNoop();
testOpenWithUpgrades();
testSessionOutboundQueue();
testVirtualFdRange();
testSharedStoreConfig();
testSharedOutboundQueue();
testSingleSidWaiter();
testShortPollWaitConfig();
testConnectAckDetect();
testNamespaceConnectViaPolling();
testMemoryOutboundMaxLen();
testEnsureLocalFromRedis();
testSessionTouchIntervalConfig();
testDualGetPostConnectAckRegression(false);
testDualGetPostConnectAckRegression(true);

echo "All websocket socket.io polling tests passed.\n";
