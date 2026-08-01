<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushFanoutResult;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Offline\CallableOfflineReconnectHook;
use Swoolefy\Websocket\Offline\InMemoryOfflineMessageStore;
use Swoolefy\Websocket\Offline\OfflineMessageCoordinator;
use Swoolefy\Websocket\Offline\OfflineMessageStoreFactory;
use Swoolefy\Websocket\Offline\OfflineReconnectHookFactory;

/**
 * WebSocket 离线必达单元测试。
 */
final class WebsocketOfflineMessageTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->teardownOfflineConfig();
        parent::tearDown();
    }

    /** @param array<string, mixed> $offlineExtra */
    private function bootOfflineConfig(InMemoryOfflineMessageStore $store, array $offlineExtra = []): void
    {
        ClusterConfig::setWebsocketOverride([
            'offline' => array_merge([
                'enable' => true,
                'events' => ['chat.private'],
                'replay_on_reconnect' => false,
                'replay_limit' => 50,
            ], $offlineExtra),
        ]);
        OfflineMessageStoreFactory::setOverride($store);
        OfflineReconnectHookFactory::reset();
    }

    private function teardownOfflineConfig(): void
    {
        ClusterConfig::setWebsocketOverride(null);
        OfflineMessageStoreFactory::reset();
        OfflineReconnectHookFactory::reset();
    }

    public function testStoreWhenPushMisses(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 0);
        $this->assertSame('1', $id);
        $this->assertSame(1, $store->countPending('user-b'));

        $skipped = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 1);
        $this->assertNull($skipped);
    }

    public function testAfterPushSkipsWhenRemoteQueued(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $result = new PushFanoutResult();
        $result->targetCount = 2;
        $result->remoteTargetCount = 2;

        $id = OfflineMessageCoordinator::maybeStoreOfflineAfterPush(
            'user-b',
            'chat.private',
            ['msg' => 'hi'],
            $result
        );
        $this->assertNull($id);
        $this->assertSame(0, $store->countPending('user-b'));
    }

    public function testAfterDeliveryGoneStoresOffline(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $message = PushMessage::event(
            [['fd' => 9, 'conn_id' => 'ws-m2:9']],
            'chat.private',
            ['to_user_id' => 'user-b', 'msg' => 'hi'],
            'ws-m1',
            '',
            '',
            'user-b'
        );

        $result = new PushDeliveryResult();
        $result->recordOutcome('gone');

        $id = OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $result);
        $this->assertSame(1, $id);
        $this->assertSame(1, $store->countPending('user-b'));
    }

    public function testAfterDeliveryNoStoreWhenDeliveredOrFailed(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $message = PushMessage::event(
            [['fd' => 1, 'conn_id' => 'ws:1']],
            'chat.private',
            ['to_user_id' => 'user-b', 'msg' => 'x'],
            'test',
            '',
            '',
            'user-b'
        );

        $delivered = new PushDeliveryResult();
        $delivered->recordOutcome('delivered');
        $delivered->recordOutcome('gone');
        $this->assertSame(0, OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $delivered));

        $failed = new PushDeliveryResult();
        $failed->recordOutcome('gone');
        $failed->recordOutcome('failed');
        $this->assertSame(0, OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $failed));
        $this->assertSame(0, $store->countPending('user-b'));
    }

    public function testGroupPushOfflineUserIds(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $result = new PushFanoutResult();
        $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterGroupPush(
            'room-a',
            'chat.private',
            ['offline_user_ids' => ['user-b', 'user-c'], 'msg' => 'hi'],
            $result
        );
        $this->assertSame(2, $stored);
        $this->assertSame(1, $store->countPending('user-b'));
        $this->assertSame(1, $store->countPending('user-c'));
    }

    public function testGroupDeliveryGonePerUser(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $message = PushMessage::event(
            [
                ['fd' => 1, 'conn_id' => 'ws:1'],
                ['fd' => 2, 'conn_id' => 'ws:2'],
            ],
            'chat.private',
            ['msg' => 'hi'],
            'test',
            '',
            '',
            null,
            'room-a',
            'group'
        );

        $result = new PushDeliveryResult();
        $result->recordTargetOutcome(1, 'ws:1', 'user-a', 'delivered');
        $result->recordTargetOutcome(2, 'ws:2', 'user-b', 'gone');

        $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $result);
        $this->assertSame(1, $stored);
        $this->assertSame(0, $store->countPending('user-a'));
        $this->assertSame(1, $store->countPending('user-b'));
    }

    public function testBroadcastPushOfflineUserIds(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store, ['events' => ['*']]);

        $result = new PushFanoutResult();
        $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterBroadcastPush(
            'system.notice',
            ['offline_user_ids' => ['user-x'], 'text' => 'maint'],
            $result
        );
        $this->assertSame(1, $stored);
        $this->assertSame(1, $store->countPending('user-x'));
    }

    public function testEventFilter(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store, ['events' => ['notify.message']]);

        $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['x' => 1], 0);
        $this->assertNull($id);

        $id2 = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'notify.message', ['x' => 1], 0);
        $this->assertSame('1', $id2);
    }

    public function testPullAndAck(): void
    {
        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store);

        $store->store('user-a', 'chat.private', ['m' => 1]);
        $store->store('user-a', 'chat.private', ['m' => 2]);

        $page = OfflineMessageCoordinator::pullPending('user-a', 1);
        $this->assertCount(1, $page['messages']);
        $this->assertSame(2, $page['pending_total']);

        $acked = OfflineMessageCoordinator::ackDelivered('user-a', [(string) $page['messages'][0]['id']]);
        $this->assertSame(1, $acked);
        $this->assertSame(1, $store->countPending('user-a'));
    }

    public function testOnReconnectHook(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('swoole extension unavailable');
        }

        $store = new InMemoryOfflineMessageStore();
        $this->bootOfflineConfig($store, [
            'events' => ['*'],
            'replay_on_reconnect' => false,
        ]);

        $hookCalled = false;
        OfflineReconnectHookFactory::setOverride(new CallableOfflineReconnectHook(
            function ($server, $fd, $userId, $replayedCount) use (&$hookCalled) {
                $hookCalled = $userId === 'user-hook' && $replayedCount === 0;
            }
        ));

        $server = new \Swoole\WebSocket\Server('127.0.0.1', 0);
        OfflineMessageCoordinator::onUserOnline($server, 1, 'user-hook');
        $this->assertTrue($hookCalled);
    }

    public function testDisabledNoOp(): void
    {
        ClusterConfig::setWebsocketOverride(['offline' => ['enable' => false]]);
        OfflineMessageStoreFactory::setOverride(new InMemoryOfflineMessageStore());

        $this->assertFalse(OfflineMessageCoordinator::isEnabled());
        $this->assertNull(OfflineMessageCoordinator::maybeStoreOffline('u', 'e', [], 0));
    }
}
