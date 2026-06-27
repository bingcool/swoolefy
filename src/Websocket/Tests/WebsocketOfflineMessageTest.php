<?php
/**
 * WebSocket 离线必达单元测试。
 *
 * 覆盖 pushToUser / pushToGroup / broadcast 两阶段离线落库：
 * 1. 推送阶段（targetCount=0）→ offline_user_ids 或单用户落库
 * 2. 投递阶段（gone 僵尸 conn）→ 按 user_id 聚合后落库
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

/** 注入离线配置 + 内存 Store，供单测使用（不依赖 MySQL / Redis） */
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

/**
 * 场景：pushToUser 命中 0 条连接（用户完全离线）。
 *
 * 前置：offline.enable=true，事件在白名单内。
 * 操作：maybeStoreOffline(..., deliveredCount=0)。
 * 预期：写入离线表；deliveredCount>0 时不写（用户在线已送达）。
 */
function testStoreWhenPushMisses(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 0);
    assertTrue($id === '1', 'should store offline message');
    assertTrue($store->countPending('user-b') === 1, 'pending count');

    // 模拟用户在线、push 已成功送达
    $skipped = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['msg' => 'hi'], 1);
    assertTrue($skipped === null, 'online push should not store');

    teardownOfflineConfig();
    echo "[OK] store when push misses\n";
}

/**
 * 场景：集群 pushToUser 索引命中连接，消息已排队到远端 Stream（P0-1）。
 *
 * 前置：PushFanoutResult.targetCount>0（Redis 有 conn 索引）。
 * 操作：maybeStoreOfflineAfterPush()。
 * 预期：推送阶段不落库——可能仍在投递中，或 gone 时由投递阶段回补。
 */
function testAfterPushSkipsWhenRemoteQueued(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $result = new PushFanoutResult();
    // 模拟 Redis 索引命中 2 条 conn，均已 XADD 到远端节点
    $result->targetCount = 2;
    $result->remoteTargetCount = 2;

    $id = OfflineMessageCoordinator::maybeStoreOfflineAfterPush('user-b', 'chat.private', ['msg' => 'hi'], $result);
    assertTrue($id === null, 'remote queued should not store at push time');
    assertTrue($store->countPending('user-b') === 0, 'no pending at push');

    teardownOfflineConfig();
    echo "[OK] after push skips when remote queued\n";
}

/**
 * 场景：僵尸连接——Redis 索引仍有 conn，但本地 fd 已断开（gone）。
 *
 * 前置：PushMessage 含 recipient_user_id；PushDeliveryResult 全部 gone。
 * 操作：maybeStoreOfflineAfterDelivery()（PushDeliveryHandler 投递后调用）。
 * 预期：按 recipient_user_id 回补离线表，保证用户上线后可补推。
 */
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
    assertTrue($id === 1, 'gone delivery should store offline');
    assertTrue($store->countPending('user-b') === 1, 'pending after gone');

    teardownOfflineConfig();
    echo "[OK] after delivery gone stores offline\n";
}

/**
 * 场景：投递结果混合 outcome，验证不落库边界。
 *
 * Case A — 部分 delivered + 部分 gone：同一用户任一 fd 送达即视为已送达，不落库。
 * Case B — gone + failed：failed 表示 fd 在线但 push 失败，PEL 会重试，不应提前写离线表。
 */
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

    // Case A：user-b 有一 fd delivered，另一 fd gone → 整用户跳过离线
    $delivered = new PushDeliveryResult();
    $delivered->recordOutcome('delivered');
    $delivered->recordOutcome('gone');
    assertTrue(OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $delivered) === 0, 'partial delivered');

    // Case B：gone + failed → 等 Streams PEL 重投，不在此落库
    $failed = new PushDeliveryResult();
    $failed->recordOutcome('gone');
    $failed->recordOutcome('failed');
    assertTrue(OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $failed) === 0, 'failed should retry');

    assertTrue($store->countPending('user-b') === 0, 'no offline stored');

    teardownOfflineConfig();
    echo "[OK] after delivery no store when delivered or failed\n";
}

/**
 * 场景：pushToGroup 时群内无任何在线连接（targetCount=0）。
 *
 * 前置：业务在 data 中传入 offline_user_ids（框架 Redis 无群成员表）。
 * 操作：maybeStoreOfflineAfterGroupPush()，FanoutResult 默认 targetCount=0。
 * 预期：列表中每个离线 user_id 各写一条离线消息。
 */
function testGroupPushOfflineUserIds(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

    $result = new PushFanoutResult();
    // 默认 targetCount=0，等价于群内无在线 conn
    $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterGroupPush(
        'room-a',
        'chat.private',
        ['offline_user_ids' => ['user-b', 'user-c'], 'msg' => 'hi'],
        $result
    );
    assertTrue($stored === 2, 'should store for offline group members');
    assertTrue($store->countPending('user-b') === 1, 'user-b pending');
    assertTrue($store->countPending('user-c') === 1, 'user-c pending');

    teardownOfflineConfig();
    echo "[OK] group push offline user ids\n";
}

/**
 * 场景：pushToGroup 群内有在线用户，但部分 fd 已断开（僵尸 conn）。
 *
 * 前置：fanout_scope=group，两 target 分属 user-a / user-b。
 * 操作：recordTargetOutcome 分别 delivered / gone → maybeStoreOfflineAfterDelivery()。
 * 预期：仅 user-b（gone）落库；user-a（delivered）跳过。
 */
function testGroupDeliveryGonePerUser(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store);

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
    // 按 user 维度记录 outcome，模拟 PushDeliveryHandler 投递明细
    $result->recordTargetOutcome(1, 'ws:1', 'user-a', 'delivered');
    $result->recordTargetOutcome(2, 'ws:2', 'user-b', 'gone');

    $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $result);
    assertTrue($stored === 1, 'only gone user stored');
    assertTrue($store->countPending('user-a') === 0, 'delivered user skip');
    assertTrue($store->countPending('user-b') === 1, 'gone user stored');

    teardownOfflineConfig();
    echo "[OK] group delivery gone per user\n";
}

/**
 * 场景：broadcast 时集群无任何在线节点（targetCount=0）。
 *
 * 前置：events=['*'] 允许 system.notice；data 含 offline_user_ids。
 * 操作：maybeStoreOfflineAfterBroadcastPush()，空 FanoutResult。
 * 预期：对 offline_user_ids 中的用户落库（与 pushToGroup 推送阶段逻辑对称）。
 */
function testBroadcastPushOfflineUserIds(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store, ['events' => ['*']]);

    $result = new PushFanoutResult();
    // 默认 targetCount=0，等价于集群无在线节点
    $stored = OfflineMessageCoordinator::maybeStoreOfflineAfterBroadcastPush(
        'system.notice',
        ['offline_user_ids' => ['user-x'], 'text' => 'maint'],
        $result
    );
    assertTrue($stored === 1, 'broadcast offline store');
    assertTrue($store->countPending('user-x') === 1, 'user-x pending');

    teardownOfflineConfig();
    echo "[OK] broadcast push offline user ids\n";
}

/**
 * 场景：offline.events 事件白名单过滤。
 *
 * 前置：events 仅允许 notify.message。
 * 操作：对 chat.private（不在名单）和 notify.message（在名单）各尝试落库。
 * 预期：白名单外事件跳过；白名单内正常写入。
 */
function testEventFilter(): void
{
    $store = new InMemoryOfflineMessageStore();
    bootOfflineConfig($store, ['events' => ['notify.message']]);

    $id = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'chat.private', ['x' => 1], 0);
    assertTrue($id === null, 'event not in allowlist should skip store');

    // notify.message 在白名单内，应正常落库
    $id2 = OfflineMessageCoordinator::maybeStoreOffline('user-b', 'notify.message', ['x' => 1], 0);
    assertTrue($id2 === '1', 'allowed event should store');

    teardownOfflineConfig();
    echo "[OK] event filter\n";
}

/**
 * 场景：客户端主动拉取模式（非 replay_on_reconnect 自动补推）。
 *
 * 前置：user-a 有两条 pending 离线消息。
 * 操作：pullPending(limit=1) 分页拉取 → ackDelivered 确认一条。
 * 预期：分页返回 1 条且 pending_total 仍为 2；ACK 后 pending 减为 1。
 */
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

/**
 * 场景：用户上线时 on_reconnect 业务钩子触发时机。
 *
 * 前置：replay_on_reconnect=false（无自动补推）；注册 CallableOfflineReconnectHook。
 * 操作：onUserOnline(server, fd, userId)。
 * 预期：钩子被调用，且 replayedCount=0 传入（补推在钩子之前已完成/跳过）。
 *
 * 依赖 Swoole 扩展创建 Server 实例；无扩展时 SKIP。
 */
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

/**
 * 场景：offline.enable=false 总开关关闭。
 *
 * 操作：isEnabled() 与 maybeStoreOffline(..., deliveredCount=0)。
 * 预期：协调器视为未启用，任何路径均不写入离线表。
 */
function testDisabledNoOp(): void
{
    ClusterConfig::setWebsocketOverride(['offline' => ['enable' => false]]);
    OfflineMessageStoreFactory::setOverride(new InMemoryOfflineMessageStore());

    assertTrue(OfflineMessageCoordinator::isEnabled() === false, 'disabled offline');
    assertTrue(OfflineMessageCoordinator::maybeStoreOffline('u', 'e', [], 0) === null, 'disabled store');

    teardownOfflineConfig();
    echo "[OK] disabled no-op\n";
}

// --- 执行顺序：pushToUser → 群/广播 → 配置与拉取 ---
// --- pushToUser ---
testStoreWhenPushMisses();
testAfterPushSkipsWhenRemoteQueued();
testAfterDeliveryGoneStoresOffline();
testAfterDeliveryNoStoreWhenDeliveredOrFailed();
// --- pushToGroup / broadcast ---
testGroupPushOfflineUserIds();
testGroupDeliveryGonePerUser();
testBroadcastPushOfflineUserIds();
// --- 配置 / 拉取 / 钩子 ---
testEventFilter();
testPullAndAck();
testOnReconnectHook();
testDisabledNoOp();

echo "All websocket offline message tests passed.\n";
