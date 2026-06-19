<?php
/**
 * WebSocket cluster registry/push tests.
 *
 * Run:
 *   SWOOLEFY_CLI_ENV=dev php src/Websocket/Tests/WebsocketClusterTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\Cluster\ExternalPushPublisher;
use Swoolefy\Websocket\Cluster\PushMessage;
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
    assertTrue(RedisConnectionRegistry::getConnectionMeta($connId) === null, 'conn meta should be removed');

    echo "[OK] redis registry lifecycle\n";
}

\Swoole\Coroutine\run(function () {
    testConnIdParser();
    testPushMessageCodec();

    if (!redisAvailable()) {
        echo "[SKIP] redis tests (redis unavailable)\n";
        return;
    }

    testRedisRegistryLifecycle();
    testExternalPushPublisher();
});

echo "All websocket cluster tests passed.\n";
