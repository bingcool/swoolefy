<?php
/**
 * 推送去重（PushDedupStore / PushDeliveryResult）单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketPushDedupTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDedupStore;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('APP_NAME', 'WebsocketService');

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 启用内存去重 + 集群配置（不访问 Redis） */
function bootDedupConfig(): void
{
    ClusterConfig::setWebsocketOverride([
        'cluster' => [
            'enable' => true,
            'server_id' => 'ws-dedup-test',
            'redis' => ['key_prefix' => 'ws:test:'],
            'push' => [
                'dedup' => ['enable' => true, 'ttl' => 3600],
            ],
        ],
    ]);
    PushDedupStore::useMemoryStoreForTest();
}

/** 清理单测注入 */
function teardownDedupConfig(): void
{
    ClusterConfig::setWebsocketOverride(null);
    PushDedupStore::resetForTest();
}

/** 首次 msg_id 非重复；mark 后变为重复 */
function testMarkAndDetectDuplicate(): void
{
    bootDedupConfig();

    $msgId = 'dedup-msg-001';
    assertTrue(!PushDedupStore::isDuplicate($msgId), 'first time not duplicate');
    PushDedupStore::markProcessed($msgId);
    assertTrue(PushDedupStore::isDuplicate($msgId), 'after mark should be duplicate');

    teardownDedupConfig();
    echo "[OK] mark and detect duplicate\n";
}

/** duplicateSkipped 结果应 ACK，避免 PEL 无限重试 */
function testDuplicateResultShouldAck(): void
{
    $result = PushDeliveryResult::duplicateSkipped();
    assertTrue($result->duplicateSkipped, 'flag set');
    assertTrue($result->shouldAck(), 'duplicate should ack');
    assertTrue($result->delivered === 0, 'no fd delivered');

    echo "[OK] duplicate result should ack\n";
}

/** dedup 关闭时不标记、不拦截 */
function testDedupDisabled(): void
{
    ClusterConfig::setWebsocketOverride([
        'cluster' => [
            'enable' => true,
            'server_id' => 'ws-dedup-off',
            'push' => ['dedup' => ['enable' => false]],
        ],
    ]);
    PushDedupStore::useMemoryStoreForTest();

    PushDedupStore::markProcessed('x');
    assertTrue(!PushDedupStore::isDuplicate('x'), 'disabled dedup never marks');

    teardownDedupConfig();
    echo "[OK] dedup disabled\n";
}

/** 空 msg_id 不参与去重 */
function testEmptyMsgIdSkipped(): void
{
    bootDedupConfig();

    PushDedupStore::markProcessed('');
    assertTrue(!PushDedupStore::isDuplicate(''), 'empty msg_id ignored');

    teardownDedupConfig();
    echo "[OK] empty msg_id skipped\n";
}

testMarkAndDetectDuplicate();
testDuplicateResultShouldAck();
testDedupDisabled();
testEmptyMsgIdSkipped();

echo "All websocket push dedup tests passed.\n";
