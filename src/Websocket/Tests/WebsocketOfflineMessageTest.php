<?php
/**
 * WebSocket 离线必达单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketOfflineMessageTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushFanoutResult;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Offline\InMemoryOfflineMessageStore;
use Swoolefy\Websocket\Offline\OfflineMessageCoordinator;
use Swoolefy\Websocket\Offline\OfflineMessageStoreFactory;
use Swoolefy\Websocket\Offline\OfflineReconnectHookFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 注入离线配置 + 内存 Store，供单测使用 */
function bootOfflineConfig(InMemoryOfflineMessageStore $store, array $offlineExtra = []): void
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

/** 清理单测注入的配置与 Factory 单例 */
function teardownOfflineConfig(): void
{
    ClusterConfig::setWebsocketOverride(null);
    OfflineMessageStoreFactory::reset();
    OfflineReconnectHookFactory::reset();
}

/** deliveredCount=0 时落库；>0 时不落库 */
function testStoreWhenPushMisses(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 0);
    assertTrue($id === '1', 'should store offline message');
    assertTrue($store->countPending('user-b') === 1, 'pending count');

    $skipped = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 1);
    assertTrue($skipped === null, 'online push should not store');

    teardownOfflineConfig();
    echo "[OK] store when push misses\n";
}

/** 索引有远端 target 时不应在 push 阶段落库（等待投递或 gone 回补） */
function testAfterPushSkipsWhenRemoteQueued(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $result = new PushFanoutResult();
    $result->targetCount = 2;
    $result->remoteTargetCount = 2;

    $id = OfflineMessageCoordinator::maybeStoreOfflineAfterPush('user-b', 'chat.private', ['msg' => 'hi'], $result);
    assertTrue($id === null, 'remote queued should not store at push time');
    assertTrue($store->countPending('user-b') === 0, 'no pending at push');

    teardownOfflineConfig();
    echo "[OK] after push skips when remote queued\n";
}

/** 投递全部 gone 时应回补离线表 */
function testAfterDeliveryGoneStoresOffline(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

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
    assertTrue($id === '1', 'gone delivery should store offline');
    assertTrue($store->countPending('user-b') === 1, 'pending after gone');

    teardownOfflineConfig();
    echo "[OK] after delivery gone stores offline\n";
}

/** 部分 delivered 时不回补；存在 failed 时不回补（留 PEL 重试） */
function testAfterDeliveryNoStoreWhenDeliveredOrFailed(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

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
    assertTrue(OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $delivered) === null, 'partial delivered');

    $failed = new PushDeliveryResult();
    $failed->recordOutcome('gone');
    $failed->recordOutcome('failed');
    assertTrue(OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $failed) === null, 'failed should retry');

    assertTrue($store->countPending('user-b') === 0, 'no offline stored');

    teardownOfflineConfig();
    echo "[OK] after delivery no store when delivered or failed\n";
}

/** offline.events 白名单：不在列表的事件不落库 */
function testEventFilter(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store, ['events' => ['notify.message']]);

    $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['x' => 1], 0);
    assertTrue($id === null, 'event not in allowlist should skip store');

    $id2 = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'notify.message', ['x' => 1], 0);
    assertTrue($id2 === '1', 'allowed event should store');

    teardownOfflineConfig();
    echo "[OK] event filter\n";
}

/** 拉取分页 + ACK 后 pending 计数减少 */
function testPullAndAck(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $store->store('user-a', 'chat.private', ['m' => 1]);
    $store->store('user-a', 'chat.private', ['m' => 2]);

    $page = OfflineMessageCoordinator::pullPending('user-a', 1);
    assertTrue(count($page['messages']) === 1, 'pull page size');
    assertTrue($page['pending_total'] === 2, 'pending total');

    $acked = OfflineMessageCoordinator::ackDelivered('user-a', [(string) $page['messages'][0]['id']]);
    assertTrue($acked === 1, 'ack one message');
    assertTrue($store->countPending('user-a') === 1, 'one pending left');

    teardownOfflineConfig();
    echo "[OK] pull and ack\n";
}

/** onUserOnline 在 replay 之后应触发 on_reconnect 钩子 */
function testOnReconnectHook(): void
{
    if (!extension_loaded('swoole')) {
        echo "[SKIP] on reconnect hook (swoole extension unavailable)\n";

        return;
    }

    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store, [
        'events' => ['*'],
        'replay_on_reconnect' => false,
    ]);

    $hookCalled = false;
    OfflineReconnectHookFactory::setOverride(new \Swoolefy\Websocket\Offline\CallableOfflineReconnectHook(
        function ($server, $fd, $userId, $replayedCount) use (&$hookCalled) {
            $hookCalled = $userId === 'user-hook' && $replayedCount === 0;
        }
    ));

    $server = new \Swoole\WebSocket\Server('127.0.0.1', 0);
    OfflineMessageCoordinator::onUserOnline($server, 1, 'user-hook');
    assertTrue($hookCalled, 'on_reconnect hook should fire');

    teardownOfflineConfig();
    echo "[OK] on reconnect hook\n";
}

/** offline.enable=false 时 isEnabled 为 false 且 maybeStoreOffline 不写入 */
function testDisabledNoOp(): void
{
    ClusterConfig::setWebsocketOverride(['offline' => ['enable' => false]]);
    OfflineMessageStoreFactory::setOverride(new InMemoryOfflineMessageStore());

    assertTrue(OfflineMessageCoordinator::isEnabled() === false, 'disabled offline');
    assertTrue(OfflineMessageCoordinator::maybeStoreOffline('u', 'e', [], 0) === null, 'disabled store');

    teardownOfflineConfig();
    echo "[OK] disabled no-op\n";
}

testStoreWhenPushMisses();
testAfterPushSkipsWhenRemoteQueued();
testAfterDeliveryGoneStoresOffline();
testAfterDeliveryNoStoreWhenDeliveredOrFailed();
testEventFilter();
testPullAndAck();
testOnReconnectHook();
testDisabledNoOp();

echo "All websocket offline message tests passed.\n";
