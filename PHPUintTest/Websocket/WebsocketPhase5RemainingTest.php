<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use PHPUintTest\Websocket\Support\RedisAvailability;
use PHPUintTest\Websocket\Support\WebsocketAppConstants;
use Swoole\Http\Request;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\Cluster\ClusterRedisException;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingConfig;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingSessionRegistry;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 阶段五剩余项 7.6～7.9（审计 45、46、47、50）。
 *
 * 覆盖：Polling sid 元数据顺序、跨节点 upgrade ensureLocal、
 * Streams 部分失败不 ACK、WebSocket onOpen 首包 opening 门禁。
 */
final class WebsocketPhase5RemainingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
    }

    protected function tearDown(): void
    {
        SocketIOSessionManager::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        ClusterConfig::setWebsocketOverride(null);
        ClusterNodeIdentity::reset();
        WebsocketConnectionManager::resetLifecycleForTest();
        parent::tearDown();
    }

    private function setupPollingTables(): void
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

    private function requireRedis(): void
    {
        if (!RedisAvailability::isAvailable()) {
            $this->markTestSkipped('redis unavailable');
        }
    }

    /**
     * 测 7.6：openPolling 先于 bindSid 时连接已含 user_id；失败回滚不留孤儿 sid。
     * 对应问题：先 bind 再 open 时 Redis Hash user_id 为空。
     */
    public function testPollingSidMetaOrderOpenBeforeBind(): void
    {
        $this->setupPollingTables();
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => false],
            'socketio' => ['polling' => ['shared_store' => 'memory']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);

        $sid = 'phase5-sid-order';
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        $request = new Request();
        $request->server = ['remote_addr' => '127.0.0.1'];
        $request->header = ['user-agent' => 'phase5-test'];
        $request->get = [];

        WebsocketConnectionManager::openPolling($request, [
            'user_id' => 'user-phase5',
            'sid' => $sid,
            'virtual_fd' => $virtualFd,
        ]);
        $conn = WebsocketConnectionManager::getConnection($virtualFd);
        $this->assertNotNull($conn);
        $this->assertSame('user-phase5', (string) ($conn['user_id'] ?? ''));

        SocketIOSessionManager::bindSid($sid, $virtualFd, (string) ($conn['conn_id'] ?? ''));
        $this->assertSame($virtualFd, SocketIOSessionManager::getVirtualFd($sid));

        WebsocketConnectionManager::closePollingVirtual($virtualFd);
        SocketIOSessionManager::destroySession($sid);
        $this->assertNull(WebsocketConnectionManager::getConnection($virtualFd));
        $this->assertSame(0, SocketIOSessionManager::getVirtualFd($sid));
    }

    /**
     * 测 7.6（Redis）：open→bind 后 poll:sid Hash 始终含正确 user_id。
     */
    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testPollingSidRedisHashContainsUserId(): void
    {
        $this->requireRedis();
        $this->setupPollingTables();

        ClusterConfig::setWebsocketOverride([
            'cluster' => [
                'enable' => true,
                'server_id' => 'ws-phase5-76',
                'redis' => ClusterConfig::redis(),
            ],
            'socketio' => ['polling' => ['shared_store' => 'redis']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        ClusterNodeIdentity::reset();

        $sid = 'phase5-redis-sid-' . bin2hex(random_bytes(3));
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        $request = new Request();
        $request->server = ['remote_addr' => '127.0.0.1'];
        $request->header = [];
        $request->get = [];

        try {
            WebsocketConnectionManager::openPolling($request, [
                'user_id' => 'redis-user-76',
                'sid' => $sid,
                'virtual_fd' => $virtualFd,
            ]);
            $connId = (string) ((WebsocketConnectionManager::getConnection($virtualFd)['conn_id'] ?? ''));
            SocketIOSessionManager::bindSid($sid, $virtualFd, $connId);

            $meta = SocketIOPollingSessionRegistry::fetchRedisMeta($sid);
            $this->assertIsArray($meta);
            $this->assertSame('redis-user-76', (string) ($meta['user_id'] ?? ''));
            $this->assertSame((string) $virtualFd, (string) ($meta['virtual_fd'] ?? ''));
        } finally {
            WebsocketConnectionManager::closePollingVirtual($virtualFd);
            SocketIOSessionManager::destroySession($sid);
            ClusterRedisClient::resetSharedAdapter();
        }
    }

    /**
     * 测 7.7：ensurePollingShadow + resolveSession；伪造 sid 解析为 0（upgrade 应 1008）。
     */
    public function testUpgradeEnsureLocalShadowAndForgedSid(): void
    {
        $this->setupPollingTables();
        ClusterConfig::setWebsocketOverride([
            'cluster' => ['enable' => false],
            'socketio' => ['polling' => ['shared_store' => 'memory']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(false);

        $sid = 'phase5-upgrade-local';
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        SocketIOSessionManager::bindSid($sid, $virtualFd);

        WebsocketConnectionManager::ensurePollingShadow($sid, $virtualFd, [
            'user_id' => 'u-upgrade',
            'virtual_fd' => (string) $virtualFd,
            'conn_id' => 'test:' . $virtualFd,
            'last_active_at' => (string) time(),
        ]);
        $conn = WebsocketConnectionManager::getConnection($virtualFd);
        $this->assertNotNull($conn);
        $this->assertSame(1, (int) ($conn['is_polling'] ?? 0));
        $this->assertSame('u-upgrade', (string) ($conn['user_id'] ?? ''));
        $this->assertSame($virtualFd, SocketIOSessionManager::resolveSession($sid));

        // 伪造 sid：resolve 失败 → upgrade 路径应 disconnect 1008
        $this->assertSame(0, SocketIOSessionManager::resolveSession('forged-sid-xxx'));
    }

    /**
     * 测 7.7（Redis）：跨节点 ensureLocal 从 Redis 回填 Table + shadow。
     */
    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testEnsureLocalFromRedisCreatesShadow(): void
    {
        $this->requireRedis();
        $this->setupPollingTables();

        ClusterConfig::setWebsocketOverride([
            'cluster' => [
                'enable' => true,
                'server_id' => 'ws-phase5-77',
                'redis' => ClusterConfig::redis(),
            ],
            'socketio' => ['polling' => ['shared_store' => 'redis']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
        ClusterNodeIdentity::reset();

        $sid = 'phase5-ensure-' . bin2hex(random_bytes(3));
        $virtualFd = 0x40000000 + random_int(1000, 9999);

        try {
            // 先写入连接再 register，保证 Redis meta 含 user_id
            $request = new Request();
            $request->server = ['remote_addr' => '127.0.0.1'];
            $request->header = [];
            $request->get = [];
            WebsocketConnectionManager::openPolling($request, [
                'user_id' => 'cross-node-user',
                'sid' => $sid,
                'virtual_fd' => $virtualFd,
            ]);
            SocketIOSessionManager::bindSid($sid, $virtualFd, 'ws-phase5-77:' . $virtualFd);

            // 清掉本机 Table/连接，模拟节点 B
            if (TableManager::isExistTable(SocketIOPollingSessionRegistry::TABLE_POLLING_SID)) {
                TableManager::del(SocketIOPollingSessionRegistry::TABLE_POLLING_SID, $sid);
            }
            if (TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
                TableManager::del(WebsocketConnectionManager::TABLE_CONNECTIONS, (string) $virtualFd);
            }

            $resolved = SocketIOSessionManager::resolveSession($sid, true);
            $this->assertSame($virtualFd, $resolved);
            $shadow = WebsocketConnectionManager::getConnection($virtualFd);
            $this->assertNotNull($shadow);
            $this->assertSame(1, (int) ($shadow['is_polling'] ?? 0));
            $this->assertSame('cross-node-user', (string) ($shadow['user_id'] ?? ''));
        } finally {
            WebsocketConnectionManager::closePollingVirtual($virtualFd);
            SocketIOSessionManager::destroySession($sid);
            ClusterRedisClient::resetSharedAdapter();
        }
    }

    /**
     * 测 7.7：resolveSession(strict) 在 Redis 不可达时抛 ClusterRedisException（upgrade → 1011）。
     */
    public function testResolveSessionStrictThrowsOnRedisError(): void
    {
        $this->setupPollingTables();
        ClusterConfig::setWebsocketOverride([
            'cluster' => [
                'enable' => true,
                'server_id' => 'ws-phase5-strict',
                'redis' => [
                    'host' => '127.0.0.1',
                    'port' => 1,
                    'timeout' => 0.2,
                    'key_prefix' => 'ws:phase5:bad:',
                ],
            ],
            'socketio' => ['polling' => ['shared_store' => 'redis']],
        ]);
        SocketIOPollingConfig::setSharedStoreOverrideForTest(true);
        ClusterNodeIdentity::reset();

        try {
            SocketIOSessionManager::resolveSession('missing-sid-strict', true);
            $this->fail('expected ClusterRedisException');
        } catch (ClusterRedisException $e) {
            $this->assertStringContainsString('redis', strtolower($e->getMessage()));
        } finally {
            ClusterRedisClient::resetSharedAdapter();
        }
    }

    /**
     * 测 7.8：全成功 ACK；部分成功+failed 不 ACK；不可重试 gone ACK；重复消费 ACK。
     */
    public function testStreamsAckCoversAllTargets(): void
    {
        $allOk = new PushDeliveryResult();
        $allOk->recordOutcome('delivered');
        $allOk->recordOutcome('delivered');
        $this->assertTrue($allOk->shouldAck());
        $this->assertSame(0, $allOk->retryableCount());

        $allFailed = new PushDeliveryResult();
        $allFailed->recordOutcome('failed');
        $this->assertFalse($allFailed->shouldAck());

        $partial = new PushDeliveryResult();
        $partial->recordOutcome('delivered');
        $partial->recordOutcome('failed');
        $this->assertFalse($partial->shouldAck());
        $this->assertSame(1, $partial->delivered);
        $this->assertSame(1, $partial->retryableCount());

        $goneOnly = new PushDeliveryResult();
        $goneOnly->recordOutcome('gone');
        $goneOnly->recordOutcome('skipped');
        $this->assertTrue($goneOnly->shouldAck());

        $this->assertTrue(PushDeliveryResult::duplicateSkipped()->shouldAck());
        $this->assertTrue(PushDeliveryResult::invalidPayload()->shouldAck());
    }

    /**
     * 测 7.9：opening 拒绝业务；ready 放行；失败/close 后 lifecycle 无残留。
     */
    public function testOnOpenOpeningBlocksFirstPacket(): void
    {
        $fd = 9001;
        WebsocketConnectionManager::resetLifecycleForTest();

        WebsocketConnectionManager::markOpening($fd);
        $this->assertTrue(WebsocketConnectionManager::isConnectionOpening($fd));
        $this->assertFalse(WebsocketConnectionManager::isConnectionReady($fd));

        WebsocketConnectionManager::markReady($fd);
        $this->assertTrue(WebsocketConnectionManager::isConnectionReady($fd));
        $this->assertFalse(WebsocketConnectionManager::isConnectionOpening($fd));

        WebsocketConnectionManager::clearConnectionLifecycle($fd);
        $this->assertSame('', WebsocketConnectionManager::connectionLifecycle($fd));

        WebsocketConnectionManager::markOpening($fd);
        WebsocketConnectionManager::close($fd);
        $this->assertSame('', WebsocketConnectionManager::connectionLifecycle($fd));
    }
}
