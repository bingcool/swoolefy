<?php
/**
 * WebSocket cluster registry/push tests.
 *
 * Run:
 *   SWOOLEFY_CLI_ENV=dev php src/Websocket/Tests/WebsocketClusterTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterConnectionCoordinator;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\Cluster\ExternalPushPublisher;
use Swoolefy\Websocket\Cluster\PushDeliveryQueue;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Cluster\PushStreamPublisher;
use Swoolefy\Websocket\Cluster\RedisConnectionRegistry;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

define('APP_NAME', 'WebsocketService');
define('APP_PATH', $root . '/WebsocketService');
define('WORKER_PORT', 9508);

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function redisAvailable(): bool
{
    try {
        ClusterRedisClient::execute(static function ($redis) {
            return $redis->ping();
        });
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function testConnIdParser(): void
{
    $parsed = ClusterNodeIdentity::parseConnId('ws-prod-01:123');
    assertTrue($parsed['server_id'] === 'ws-prod-01', 'server_id parse failed');
    assertTrue($parsed['fd'] === 123, 'fd parse failed');
    echo "[OK] conn_id parser\n";
}

function testPushMessageCodec(): void
{
    $message = PushMessage::event([['fd' => 1, 'conn_id' => 'ws-01:1']], 'chat.message', ['msg' => 'hi'], 'ws-02');
    $decoded = PushMessage::decode(PushMessage::encode($message));
    assertTrue(is_array($decoded), 'push message decode failed');
    assertTrue(($decoded['event'] ?? '') === 'chat.message', 'push message event mismatch');
    echo "[OK] push message codec\n";
}

function testExternalPushPublisher(): void
{
    $wsConf = \Swoolefy\Core\SystemEnv::loadWebsocketConf();
    $wsConf['cluster']['enable'] = true;
    ClusterConfig::setWebsocketOverride($wsConf);

    $serverId = 'ws-external-push';
    $connId = $serverId . ':2001';
    $group = 'external-push-group';

    RedisConnectionRegistry::unregister($connId);
    RedisConnectionRegistry::register($connId, [
        'server_id' => $serverId,
        'fd' => 2001,
        'worker_id' => 0,
        'user_id' => '',
        'groups' => json_encode([$group], JSON_UNESCAPED_UNICODE),
        'is_socketio' => 0,
        'remote_addr' => '127.0.0.1',
        'connected_at' => time(),
        'last_active_at' => time(),
    ]);
    RedisConnectionRegistry::joinGroup($connId, $group, json_encode([$group], JSON_UNESCAPED_UNICODE));

    $count = ExternalPushPublisher::pushToGroup($group, 'chat.message', ['msg' => 'external']);
    assertTrue($count === 1, 'external push should target one connection');

    RedisConnectionRegistry::unregister($connId);
    ClusterConfig::setWebsocketOverride(null);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] external push publisher\n";
}

function testRedisRegistryLifecycle(): void
{
    $serverId = 'ws-test-node';
    $connId = $serverId . ':1001';
    $group = 'cluster-test-group';
    $userId = 'cluster-user';

    RedisConnectionRegistry::unregister($connId);

    RedisConnectionRegistry::register($connId, [
        'server_id' => $serverId,
        'fd' => 1001,
        'worker_id' => 0,
        'user_id' => $userId,
        'groups' => json_encode([$group], JSON_UNESCAPED_UNICODE),
        'is_socketio' => 0,
        'remote_addr' => '127.0.0.1',
        'connected_at' => time(),
        'last_active_at' => time(),
    ]);
    RedisConnectionRegistry::joinGroup($connId, $group, json_encode([$group], JSON_UNESCAPED_UNICODE));

    $groupConnIds = RedisConnectionRegistry::getConnIdsByGroup($group);
    assertTrue(in_array($connId, $groupConnIds, true), 'group index missing conn_id');

    $userConnIds = RedisConnectionRegistry::getConnIdsByUser($userId);
    assertTrue(in_array($connId, $userConnIds, true), 'user index missing conn_id');

    $meta = RedisConnectionRegistry::getConnectionMeta($connId);
    assertTrue(is_array($meta) && ($meta['server_id'] ?? '') === $serverId, 'conn meta missing');

    RedisConnectionRegistry::unregister($connId);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] redis registry lifecycle\n";
}

function testConnectionMetaMany(): void
{
    $serverId = 'ws-batch-meta';
    $connId1 = $serverId . ':1';
    $connId2 = $serverId . ':2';

    foreach ([$connId1, $connId2] as $connId) {
        RedisConnectionRegistry::unregister($connId);
        RedisConnectionRegistry::register($connId, [
            'server_id' => $serverId,
            'fd' => (int) substr($connId, strrpos($connId, ':') + 1),
            'worker_id' => 0,
            'user_id' => '',
            'groups' => '',
            'is_socketio' => 0,
            'remote_addr' => '127.0.0.1',
            'connected_at' => time(),
            'last_active_at' => time(),
        ]);
    }

    $metaMap = RedisConnectionRegistry::getConnectionMetaMany([$connId1, $connId2, 'missing:0']);
    assertTrue(isset($metaMap[$connId1]) && isset($metaMap[$connId2]), 'batch meta should return existing conn');
    assertTrue(!isset($metaMap['missing:0']), 'missing conn should be omitted');

    RedisConnectionRegistry::unregister($connId1);
    RedisConnectionRegistry::unregister($connId2);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] connection meta batch\n";
}

function testTouchThrottle(): void
{
    $wsConf = \Swoolefy\Core\SystemEnv::loadWebsocketConf();
    $wsConf['cluster']['enable'] = true;
    $wsConf['cluster']['touch_interval'] = 60;
    ClusterConfig::setWebsocketOverride($wsConf);
    ClusterConnectionCoordinator::resetTouchThrottle();

    $serverId = 'ws-touch-test';
    $connId = $serverId . ':1';
    $t0 = time();

    RedisConnectionRegistry::unregister($connId);
    ClusterConnectionCoordinator::onOpen(1, [
        'conn_id' => $connId,
        'server_id' => $serverId,
        'fd' => 1,
        'worker_id' => 0,
        'user_id' => '',
        'groups' => '',
        'is_socketio' => 0,
        'remote_addr' => '127.0.0.1',
        'connected_at' => $t0,
        'last_active_at' => $t0,
    ]);

    ClusterConnectionCoordinator::onTouch($connId, $t0 + 5);
    $meta = RedisConnectionRegistry::getConnectionMeta($connId);
    assertTrue((int) ($meta['last_active_at'] ?? 0) === $t0, 'touch within interval should not update redis');

    ClusterConnectionCoordinator::onTouch($connId, $t0 + 65);
    $meta = RedisConnectionRegistry::getConnectionMeta($connId);
    assertTrue((int) ($meta['last_active_at'] ?? 0) === $t0 + 65, 'touch after interval should update redis');

    RedisConnectionRegistry::unregister($connId);
    ClusterConnectionCoordinator::resetTouchThrottle();
    ClusterConfig::setWebsocketOverride(null);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] touch throttle\n";
}

function testPushDeliveryQueue(): void
{
    $wsConf = \Swoolefy\Core\SystemEnv::loadWebsocketConf();
    $wsConf['cluster']['enable'] = true;
    $wsConf['cluster']['server_id'] = 'ws-queue-test';
    $wsConf['cluster']['push']['delivery_process_num'] = 2;
    ClusterConfig::setWebsocketOverride($wsConf);

    assertTrue(ClusterConfig::pushDeliveryProcessNum() === 2, 'delivery_process_num should be 2');

    $payload = PushMessage::encode(PushMessage::event(
        [['fd' => 1, 'conn_id' => 'ws-queue-test:1']],
        'chat.message',
        ['msg' => 'queue'],
        'test'
    ));

    PushDeliveryQueue::enqueue($payload);

    ClusterRedisClient::runDedicated(static function ($redis) use ($payload) {
        $item = PushDeliveryQueue::dequeueBlocking($redis, 2);
        assertTrue($item === $payload, 'queue dequeue payload mismatch');
    });

    ClusterConfig::setWebsocketOverride(null);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] push delivery queue\n";
}

function testPushStreamPublishConsumeAck(): void
{
    // 验证 Streams 全链路：XADD → XREADGROUP → 解码 → XACK
    $wsConf = \Swoolefy\Core\SystemEnv::loadWebsocketConf();
    $wsConf['cluster']['enable'] = true;
    $wsConf['cluster']['push']['transport'] = 'streams';
    ClusterConfig::setWebsocketOverride($wsConf);

    assertTrue(ClusterConfig::usesPushStreams(), 'streams transport should be enabled');

    $serverId = 'ws-stream-target';
    $streamKey = ClusterConfig::pushStreamKeyForServer($serverId);
    $group = ClusterConfig::pushStreamGroup();
    $consumer = 'test-consumer-' . getmypid();

    $message = PushMessage::event(
        [['fd' => 9, 'conn_id' => $serverId . ':9']],
        'chat.message',
        ['msg' => 'stream'],
        'test'
    );

    $entryId = PushStreamPublisher::publish($serverId, $message);
    assertTrue($entryId !== '', 'xadd should return entry id');

    ClusterRedisClient::runDedicated(static function ($redis) use ($streamKey, $group, $consumer, $entryId) {
        PushStreamConsumer::ensureGroup($redis, $streamKey, $group);
        $entries = $redis->xReadGroup($group, $consumer, $streamKey, 1, 2000);
        assertTrue(count($entries) === 1, 'stream should have one pending message');
        assertTrue(($entries[0]['id'] ?? '') === $entryId, 'entry id mismatch');
        $decoded = PushMessage::decode($entries[0]['payload'] ?? '');
        assertTrue(is_array($decoded) && ($decoded['event'] ?? '') === 'chat.message', 'payload decode failed');
        $redis->xAck($streamKey, $group, [$entryId]);
    });

    ClusterConfig::setWebsocketOverride(null);
    ClusterRedisClient::resetSharedAdapter();

    echo "[OK] push stream publish consume ack\n";
}

\Swoole\Coroutine\run(function () {
    testConnIdParser();
    testPushMessageCodec();

    if (!redisAvailable()) {
        echo "[SKIP] redis tests (redis unavailable)\n";
        return;
    }

    testRedisRegistryLifecycle();
    testConnectionMetaMany();
    testTouchThrottle();
    testPushDeliveryQueue();
    testPushStreamPublishConsumeAck();
    testExternalPushPublisher();
});

echo "All websocket cluster tests passed.\n";
