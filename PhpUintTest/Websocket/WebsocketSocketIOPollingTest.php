<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use Swoole\Coroutine;
use Swoolefy\Core\Table\TableManager;
use PhpUintTest\TestCase;
use PhpUintTest\Websocket\Support\RedisAvailability;
use PhpUintTest\Websocket\Support\WebsocketAppConstants;
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
use Throwable;

/**
 * Socket.IO long-polling 单元测试（包批处理 / 会话队列）。
 */
final class WebsocketSocketIOPollingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
        if (extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
    }

    protected function tearDown(): void
    {
        SocketIOSessionManager::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        ClusterConfig::setWebsocketOverride(null);
        ClusterNodeIdentity::reset();
        parent::tearDown();
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function runInCoroutine(callable $fn): mixed
    {
        $result = null;
        $error = null;
        Coroutine\run(static function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (Throwable $e) {
                $error = $e;
            }
        });
        if ($error instanceof Throwable) {
            throw $error;
        }

        return $result;
    }

    private function requireRedis(): void
    {
        if (!RedisAvailability::isAvailable()) {
            $this->markTestSkipped('redis unavailable');
        }
    }

    private function setupPollingRegressionTables(): void
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

    private function registerPollingConnection(int $virtualFd, string $sid, string $userId = 'poll-test-user'): void
    {
        $this->setupPollingRegressionTables();
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

    /** @param array<string, mixed> $config */
    private function simulatePostConnect40(int $virtualFd, string $sid, array $config): string
    {
        $outbound = SocketIOHandler::handleInboundPollingBatch($virtualFd, '40', $config);
        foreach ($outbound as $packet) {
            SocketIOSessionManager::enqueueOutbound($sid, $packet);
        }

        return 'ok';
    }

    /** @param list<string> $packets @return list<string> */
    private function connectAcksFromPackets(array $packets): array
    {
        return array_values(array_filter(
            $packets,
            static fn (string $packet): bool => SocketIOPacket::isConnectAck($packet)
        ));
    }

    public function testBatchCodec(): void
    {
        $batch = SocketIOPacket::encodeBatch(['2', '3', '42["evt",{}]']);
        $this->assertStringContainsString("\x1e", $batch);

        $decoded = SocketIOPacket::decodeBatch($batch);
        $this->assertSame(['2', '3', '42["evt",{}]'], $decoded);
    }

    public function testPollingEmptyPayloadNoop(): void
    {
        $this->assertSame('', SocketIOPacket::encodeBatch([]));
        $this->assertSame(SocketIOPacket::ENGINE_NOOP, SocketIOPacket::encodePollingPayload([]));
        $this->assertSame('40{"sid":"abc"}', SocketIOPacket::encodePollingPayload(['40{"sid":"abc"}']));
    }

    public function testOpenWithUpgrades(): void
    {
        $open = SocketIOPacket::open('sid-abc', 25, 20, 1000, ['websocket']);
        $this->assertStringContainsString('"upgrades":["websocket"]', $open);
    }

    public function testSessionOutboundQueue(): void
    {
        $this->runInCoroutine(function (): void {
            SocketIOSessionManager::resetForTest();
            SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
            $sid = 'poll-session-1';
            $virtualFd = SocketIOSessionManager::allocateVirtualFd();
            SocketIOSessionManager::bindSid($sid, $virtualFd);

            $this->assertTrue(SocketIOSessionManager::enqueueOutbound($sid, '42["chat.message",{"msg":"hi"}]'));
            $packets = SocketIOSessionManager::waitOutbound($sid, 0);
            $this->assertCount(1, $packets);
            $this->assertStringStartsWith('42', $packets[0]);

            SocketIOSessionManager::resetForTest();
        });
    }

    public function testVirtualFdRange(): void
    {
        SocketIOSessionManager::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
        $fd = SocketIOSessionManager::allocateVirtualFd();
        $this->assertTrue(SocketIOSessionManager::isVirtualFd($fd));
        $this->assertFalse(SocketIOSessionManager::isVirtualFd(99));
    }

    public function testSharedStoreConfig(): void
    {
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => true],
            'socketio' => ['polling' => ['shared_store' => 'auto']],
        ]);
        $this->assertTrue(SocketIOPollingConfig::usesSharedStore());

        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => false],
            'socketio' => ['polling' => ['shared_store' => 'memory']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
        $this->assertFalse(SocketIOPollingConfig::usesSharedStore());
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testSharedOutboundQueue(): void
    {
        $this->requireRedis();

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
            $this->assertTrue(SocketIOPollingOutboundStore::enqueue($sid, '42["ping",{}]'));
            $packets = SocketIOPollingOutboundStore::drain($sid);
            $this->assertCount(1, $packets);
            $this->assertStringStartsWith('42', $packets[0]);
        } finally {
            SocketIOPollingOutboundStore::clear($sid);
            ClusterRedisClient::resetSharedAdapter();
            ClusterConfig::setWebsocketOverride(null);
            SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        }
    }

    public function testSingleSidWaiter(): void
    {
        SocketIOSessionManager::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
        $sid = 'poll-waiter-1';
        SocketIOSessionManager::bindSid($sid, SocketIOSessionManager::allocateVirtualFd());

        $first = null;
        $second = null;
        $this->runInCoroutine(function () use ($sid, &$first, &$second): void {
            Coroutine::create(static function () use ($sid, &$first): void {
                $first = SocketIOSessionManager::waitOutbound($sid, 2);
            });
            Coroutine::sleep(0.05);
            $second = SocketIOSessionManager::waitOutbound($sid, 2);
        });

        $this->assertSame([], $first);
        $this->assertSame([], $second);
    }

    public function testShortPollWaitConfig(): void
    {
        ClusterConfig::setWebsocketOverride([
            'socketio' => ['polling' => ['short_poll_wait_sec' => 3]],
        ]);
        $this->assertSame(3, SocketIOPollingConfig::shortPollWaitSec(25));

        ClusterConfig::setWebsocketOverride([
            'socketio' => ['polling' => ['short_poll_wait_sec' => 99]],
        ]);
        $this->assertSame(5, SocketIOPollingConfig::shortPollWaitSec(25));
    }

    public function testConnectAckDetect(): void
    {
        $ack = SocketIOPacket::connectAck('sid-xyz');
        $this->assertTrue(SocketIOPacket::isConnectAck($ack));
        $this->assertFalse(SocketIOPacket::isConnectAck('42["evt",{}]'));

        $chatAck = SocketIOPacket::connectAck('sid-xyz', '/chat');
        $this->assertTrue(SocketIOPacket::isConnectAck($chatAck));
        $this->assertStringContainsString('/chat,', $chatAck);
    }

    public function testNamespaceConnectViaPolling(): void
    {
        SocketIOSessionManager::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);
        SocketIONamespaceRegistry::setConfigOverride([
            'allowed_namespaces' => ['*'],
        ]);

        $virtualFd = 1073742200;
        $sid = 'ns-poll-sid';
        $this->registerPollingConnection($virtualFd, $sid);

        $config = ['socketio' => ['enable' => true, 'allowed_namespaces' => ['*']]];
        $outbound = SocketIOHandler::handleInboundPollingBatch($virtualFd, '40/chat,', $config);

        $this->assertCount(1, $outbound);
        $this->assertTrue(SocketIOPacket::isConnectAck($outbound[0]));
        $this->assertStringContainsString('/chat,', $outbound[0]);

        $connection = WebsocketConnectionManager::getConnection($virtualFd);
        $this->assertStringContainsString('/chat', (string) ($connection['socketio_namespaces'] ?? ''));
        $this->assertTrue(SocketIONamespaceRegistry::isConnected($virtualFd, '/chat'));

        SocketIONamespaceRegistry::setConfigOverride([
            'allowed_namespaces' => ['/', '/chat'],
        ]);
        $virtualFd2 = 1073742201;
        $this->registerPollingConnection($virtualFd2, 'ns-deny-sid');
        $denied = SocketIOHandler::handleInboundPollingBatch($virtualFd2, '40/admin,', $config);
        $this->assertCount(1, $denied);
        $this->assertStringContainsString('namespace not allowed', $denied[0]);
    }

    public function testMemoryOutboundMaxLen(): void
    {
        $this->runInCoroutine(function (): void {
            SocketIOSessionManager::resetForTest();
            SocketIOPollingOutboundStore::resetForTest();
            ClusterConfig::setWebsocketOverride([
                'socketio' => ['polling' => ['outbound_max_len' => 16]],
            ]);
            SocketIOPollingConfig::setSharedStoreOverrideForTest(false);

            $sid = 'mem-max-len';
            for ($i = 1; $i <= 20; $i++) {
                SocketIOPollingOutboundStore::enqueue($sid, 'p' . $i);
            }

            $packets = SocketIOPollingOutboundStore::drain($sid);
            $this->assertCount(16, $packets);
            $this->assertSame('p5', $packets[0]);
            $this->assertSame('p20', $packets[15]);
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testEnsureLocalFromRedis(): void
    {
        $this->requireRedis();

        $this->setupPollingRegressionTables();
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

            $this->assertFalse(TableManager::exist(SocketIOPollingSessionRegistry::TABLE_POLLING_SID, $sid));

            $resolved = SocketIOSessionManager::resolveSession($sid);
            $this->assertSame($virtualFd, $resolved);
            $this->assertTrue(TableManager::exist(SocketIOPollingSessionRegistry::TABLE_POLLING_SID, $sid));

            $connection = WebsocketConnectionManager::getConnection($virtualFd);
            $this->assertIsArray($connection);
            $this->assertSame(1, (int) ($connection['is_polling'] ?? 0));
            $this->assertSame('cross-node-user', $connection['user_id'] ?? '');

            $outbound = SocketIOHandler::handleInboundPollingBatch($virtualFd, '40', $config);
            $this->assertNotSame([], $this->connectAcksFromPackets($outbound));
            foreach ($outbound as $packet) {
                SocketIOSessionManager::enqueueOutbound($sid, $packet);
            }
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
    }

    public function testSessionTouchIntervalConfig(): void
    {
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['touch_interval' => 20],
            'socketio' => ['polling' => ['session_touch_interval' => 12]],
        ]);
        $this->assertSame(12, SocketIOPollingConfig::sessionTouchInterval());

        ClusterConfig::setWebsocketOverride([
            'cluster' => ['touch_interval' => 20],
            'socketio' => ['polling' => []],
        ]);
        $this->assertSame(20, SocketIOPollingConfig::sessionTouchInterval());
    }

    public function testDualGetPostConnectAckRegressionMemory(): void
    {
        $this->runDualGetPostConnectAckRegression(false);
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testDualGetPostConnectAckRegressionRedis(): void
    {
        $this->requireRedis();
        $this->runDualGetPostConnectAckRegression(true);
    }

    private function runDualGetPostConnectAckRegression(bool $useSharedStore): void
    {
        $label = $useSharedStore ? 'redis' : 'memory';

        $this->runInCoroutine(function () use ($useSharedStore, $label): void {
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
            $this->registerPollingConnection($virtualFd, $sid);

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

            $get1Started = new Coroutine\Channel(1);
            $get2Done = new Coroutine\Channel(1);
            $get1Done = new Coroutine\Channel(1);

            Coroutine::create(static function () use (
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

            $this->assertTrue((bool) $get1Started->pop(1.0), "{$label}: GET #1 should start");
            Coroutine::sleep(0.05);

            $get2StartedAt = microtime(true);
            Coroutine::create(static function () use (
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

            $this->assertTrue((bool) $get2Done->pop(1.0), "{$label}: GET #2 should finish quickly");
            $this->assertLessThan(0.25, $get2Elapsed, "{$label}: GET #2 should not block on short poll wait");
            $this->assertSame([], $get2Packets, "{$label}: GET #2 should be immediate empty poll");

            $postStartedAt = microtime(true);
            $postBody = $this->simulatePostConnect40($virtualFd, $sid, $config);
            $postElapsed = microtime(true) - $postStartedAt;
            $tPostDone = microtime(true);

            $this->assertTrue((bool) $get1Done->pop(3.0), "{$label}: GET #1 should receive ack after POST");
            $this->assertLessThan(0.5, $postElapsed, "{$label}: POST 40 should not be blocked by dual GET");

            $get1Acks = $this->connectAcksFromPackets($get1Packets);
            $get2Acks = $this->connectAcksFromPackets($get2Packets);

            $this->assertSame('ok', $postBody, "{$label}: POST should return Engine.IO ok");
            $this->assertCount(1, $get1Acks, "{$label}: GET #1 should carry one connect ack");
            $this->assertSame([], $get2Acks, "{$label}: GET #2 must not carry connect ack");
            $this->assertNotContains(SocketIOPacket::ENGINE_PING, $get1Packets);
            $this->assertStringContainsString($sid, $get1Acks[0]);

            $this->assertGreaterThan(0, $tGet2Done);
            $this->assertLessThanOrEqual($tPostDone + 0.05, $tGet2Done);
            $this->assertLessThanOrEqual($tGet1Done + 0.05, $tPostDone);
            $this->assertGreaterThanOrEqual(0.01, $get1Elapsed);

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
    }
}
