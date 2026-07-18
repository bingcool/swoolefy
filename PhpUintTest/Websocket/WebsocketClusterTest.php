<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use Swoole\Coroutine;
use Swoolefy\Core\SystemEnv;
use PhpUintTest\TestCase;
use PhpUintTest\Websocket\Support\RedisAvailability;
use PhpUintTest\Websocket\Support\WebsocketAppConstants;
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
use Throwable;

/**
 * WebSocket 集群注册 / 推送单元测试。
 *
 * 纯解析/编解码用例无 Redis；需 Redis 的用例用 Group('redis') attribute。
 */
final class WebsocketClusterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
        if (extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
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

    public function testConnIdParser(): void
    {
        $parsed = ClusterNodeIdentity::parseConnId('ws-prod-01:123');
        $this->assertSame('ws-prod-01', $parsed['server_id']);
        $this->assertSame(123, $parsed['fd']);
    }

    public function testPushMessageCodec(): void
    {
        $message = PushMessage::event(
            [['fd' => 1, 'conn_id' => 'ws-01:1']],
            'chat.message',
            ['msg' => 'hi'],
            'ws-02'
        );
        $decoded = PushMessage::decode(PushMessage::encode($message));
        $this->assertIsArray($decoded);
        $this->assertSame('chat.message', $decoded['event'] ?? '');
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testExternalPushPublisher(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $wsConf = SystemEnv::loadWebsocketConf();
            $wsConf['cluster']['enable'] = true;
            $wsConf['cluster']['server_id'] = 'ws-external-push';
            ClusterConfig::setWebsocketOverride($wsConf);
            ClusterNodeIdentity::reset();

            $serverId = 'ws-external-push';
            $connId = $serverId . ':2001';
            $group = 'external-push-group';

            try {
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
                $this->assertSame(1, $count);
            } finally {
                RedisConnectionRegistry::unregister($connId);
                ClusterConfig::setWebsocketOverride(null);
                ClusterNodeIdentity::reset();
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testRedisRegistryLifecycle(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $serverId = 'ws-test-node';
            $connId = $serverId . ':1001';
            $group = 'cluster-test-group';
            $userId = 'cluster-user';

            try {
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

                $this->assertContains($connId, RedisConnectionRegistry::getConnIdsByGroup($group));
                $this->assertContains($connId, RedisConnectionRegistry::getConnIdsByUser($userId));

                $meta = RedisConnectionRegistry::getConnectionMeta($connId);
                $this->assertIsArray($meta);
                $this->assertSame($serverId, $meta['server_id'] ?? '');
            } finally {
                RedisConnectionRegistry::unregister($connId);
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testConnectionMetaMany(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $serverId = 'ws-batch-meta';
            $connId1 = $serverId . ':1';
            $connId2 = $serverId . ':2';

            try {
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
                $this->assertArrayHasKey($connId1, $metaMap);
                $this->assertArrayHasKey($connId2, $metaMap);
                $this->assertArrayNotHasKey('missing:0', $metaMap);
            } finally {
                RedisConnectionRegistry::unregister($connId1);
                RedisConnectionRegistry::unregister($connId2);
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testTouchThrottle(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $wsConf = SystemEnv::loadWebsocketConf();
            $wsConf['cluster']['enable'] = true;
            $wsConf['cluster']['server_id'] = 'ws-touch-test';
            $wsConf['cluster']['touch_interval'] = 60;
            ClusterConfig::setWebsocketOverride($wsConf);
            ClusterNodeIdentity::reset();
            ClusterConnectionCoordinator::resetTouchThrottle();

            $serverId = 'ws-touch-test';
            $connId = $serverId . ':1';
            $t0 = time();

            try {
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
                $this->assertSame($t0, (int) ($meta['last_active_at'] ?? 0));

                ClusterConnectionCoordinator::onTouch($connId, $t0 + 65);
                $meta = RedisConnectionRegistry::getConnectionMeta($connId);
                $this->assertSame($t0 + 65, (int) ($meta['last_active_at'] ?? 0));
            } finally {
                RedisConnectionRegistry::unregister($connId);
                ClusterConnectionCoordinator::resetTouchThrottle();
                ClusterConfig::setWebsocketOverride(null);
                ClusterNodeIdentity::reset();
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testGoneCleanupAfterDeliveryGone(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $wsConf = SystemEnv::loadWebsocketConf();
            $wsConf['cluster']['enable'] = true;
            $wsConf['cluster']['server_id'] = 'ws-gone-cleanup';
            $wsConf['cluster']['gone_cleanup_interval'] = 1;
            ClusterConfig::setWebsocketOverride($wsConf);
            ClusterNodeIdentity::reset();
            ClusterConnectionCoordinator::resetTouchThrottle();

            $serverId = 'ws-gone-cleanup';
            $connId = $serverId . ':101';
            $now = time();

            try {
                RedisConnectionRegistry::unregister($connId);
                ClusterConnectionCoordinator::onOpen(101, [
                    'conn_id' => $connId,
                    'server_id' => $serverId,
                    'fd' => 101,
                    'worker_id' => 0,
                    'user_id' => '',
                    'groups' => '',
                    'is_socketio' => 0,
                    'remote_addr' => '127.0.0.1',
                    'connected_at' => $now,
                    'last_active_at' => $now,
                ]);
                $this->assertNotNull(RedisConnectionRegistry::getConnectionMeta($connId));

                ClusterConnectionCoordinator::onDeliveryGone($connId, 101);
                $this->assertNull(RedisConnectionRegistry::getConnectionMeta($connId));
            } finally {
                ClusterConnectionCoordinator::resetTouchThrottle();
                ClusterConfig::setWebsocketOverride(null);
                ClusterNodeIdentity::reset();
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testPushDeliveryQueue(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $wsConf = SystemEnv::loadWebsocketConf();
            $wsConf['cluster']['enable'] = true;
            $wsConf['cluster']['server_id'] = 'ws-queue-test';
            $wsConf['cluster']['push']['delivery_process_num'] = 2;
            ClusterConfig::setWebsocketOverride($wsConf);

            try {
                $this->assertSame(2, ClusterConfig::pushDeliveryProcessNum());

                $payload = PushMessage::encode(PushMessage::event(
                    [['fd' => 1, 'conn_id' => 'ws-queue-test:1']],
                    'chat.message',
                    ['msg' => 'queue'],
                    'test'
                ));

                PushDeliveryQueue::enqueue($payload);

                ClusterRedisClient::runDedicated(function ($redis) use ($payload): void {
                    $item = PushDeliveryQueue::dequeueBlocking($redis, 2);
                    $this->assertSame($payload, $item);
                });
            } finally {
                ClusterConfig::setWebsocketOverride(null);
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testPushStreamPublishConsumeAck(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $wsConf = SystemEnv::loadWebsocketConf();
            $wsConf['cluster']['enable'] = true;
            $wsConf['cluster']['server_id'] = 'ws-stream-target';
            $wsConf['cluster']['push']['transport'] = 'streams';
            ClusterConfig::setWebsocketOverride($wsConf);
            ClusterNodeIdentity::reset();

            try {
                $this->assertTrue(ClusterConfig::usesPushStreams());

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
                $this->assertNotSame('', $entryId);

                ClusterRedisClient::runDedicated(function ($redis) use ($streamKey, $group, $consumer, $entryId): void {
                    PushStreamConsumer::ensureGroup($redis, $streamKey, $group);
                    $entries = $redis->xReadGroup($group, $consumer, $streamKey, 1, 2000);
                    $this->assertCount(1, $entries);
                    $this->assertSame($entryId, $entries[0]['id'] ?? '');
                    $decoded = PushMessage::decode($entries[0]['payload'] ?? '');
                    $this->assertIsArray($decoded);
                    $this->assertSame('chat.message', $decoded['event'] ?? '');
                    $redis->xAck($streamKey, $group, [$entryId]);
                });
            } finally {
                ClusterConfig::setWebsocketOverride(null);
                ClusterNodeIdentity::reset();
                ClusterRedisClient::resetSharedAdapter();
            }
        });
    }

    #[\PHPUnit\Framework\Attributes\Group('redis')]
    public function testConcurrentExecuteNoSocketConflict(): void
    {
        $this->requireRedis();
        $this->runInCoroutine(function (): void {
            $errors = [];
            $waitGroup = new Coroutine\WaitGroup();
            $waitGroup->add(2);

            Coroutine::create(static function () use ($waitGroup, &$errors) {
                try {
                    ClusterRedisClient::execute(static function ($redis) {
                        $redis->ping();
                        Coroutine::sleep(0.05);
                        $redis->zRangeByScore('ws:WebsocketService:alive', '0', (string) time());
                    });
                } catch (Throwable $throwable) {
                    $errors[] = $throwable->getMessage();
                } finally {
                    $waitGroup->done();
                }
            });

            Coroutine::create(static function () use ($waitGroup, &$errors) {
                try {
                    ClusterRedisClient::execute(static function ($redis) {
                        $redis->sMembers('ws:WebsocketService:nodes');
                    });
                } catch (Throwable $throwable) {
                    $errors[] = $throwable->getMessage();
                } finally {
                    $waitGroup->done();
                }
            });

            $waitGroup->wait();
            $this->assertSame([], $errors, 'concurrent execute failed: ' . implode('; ', $errors));
        });
    }
}
