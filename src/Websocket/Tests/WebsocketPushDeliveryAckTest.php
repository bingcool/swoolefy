<?php
/**
 * Streams 推送 XACK 智能决策单元测试。
 *
 * 验证 PushDeliveryResult::shouldAck() 策略及消费进程 ACK 分支。
 *
 * Run: php src/Websocket/Tests/WebsocketPushDeliveryAckTest.php
 */

use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushDeliveryWorker;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Tests\Support\NoopClusterRedisAdapter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 根据投递 outcome 列表构造 PushDeliveryResult 测试夹具 */
function resultWithOutcomes(array $outcomes): PushDeliveryResult
{
    $result = new PushDeliveryResult();
    foreach ($outcomes as $outcome) {
        $result->recordOutcome($outcome);
    }

    return $result;
}

/** 至少一条 delivered 时应 ACK（含部分成功） */
function testShouldAckDelivered(): void
{
    $r = resultWithOutcomes(['delivered']);
    assertTrue($r->shouldAck(), 'single delivered should ack');

    $r = resultWithOutcomes(['delivered', 'gone', 'failed']);
    assertTrue($r->shouldAck(), 'partial success should ack');
    echo "[OK] shouldAck delivered\n";
}

/** 全部 gone / skipped 时 ACK，避免 PEL 无限堆积 */
function testShouldAckAllGoneOrSkipped(): void
{
    $r = resultWithOutcomes(['gone', 'gone']);
    assertTrue($r->shouldAck(), 'all gone should ack');

    $r = resultWithOutcomes(['skipped']);
    assertTrue($r->shouldAck(), 'all skipped should ack');

    $r = resultWithOutcomes(['gone', 'skipped']);
    assertTrue($r->shouldAck(), 'all gone+skipped should ack');
    echo "[OK] shouldAck gone/skipped\n";
}

/** 全部 failed 或 gone+failed 无成功时不 ACK，保留 PEL 重试 */
function testShouldNotAckFailed(): void
{
    $r = resultWithOutcomes(['failed']);
    assertTrue(!$r->shouldAck(), 'all failed should not ack');

    $r = resultWithOutcomes(['gone', 'failed']);
    assertTrue(!$r->shouldAck(), 'gone+failed without success should not ack');
    echo "[OK] shouldNotAck failed\n";
}

/** 空 targets / 非法 payload / 去重命中 ACK；server 不可用默认不 ACK，停机中则 ACK */
function testShouldAckEmptyAndInvalid(): void
{
    assertTrue((new PushDeliveryResult())->shouldAck(), 'empty targets should ack');
    assertTrue(PushDeliveryResult::invalidPayload()->shouldAck(), 'invalid payload should ack discard');
    assertTrue(PushDeliveryResult::duplicateSkipped()->shouldAck(), 'duplicate skipped should ack');
    assertTrue(!PushDeliveryResult::serverUnavailable()->shouldAck(), 'server unavailable should not ack');
    echo "[OK] shouldAck empty/invalid/server\n";
}

/** 优雅停机中 serverUnavailable 应 ACK，避免 PEL 卡满 drain_timeout */
function testShouldAckServerUnavailableDuringShutdown(): void
{
    \Swoolefy\Websocket\Cluster\ClusterConfig::setWebsocketOverride([
        'graceful_shutdown' => [
            'enable' => true,
            'drain_timeout' => 5,
        ],
    ]);
    \Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator::useMemoryFlagForTest();
    \Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator::setShuttingDownForTest(true);

    assertTrue(
        PushDeliveryResult::serverUnavailable()->shouldAck(),
        'server unavailable during shutdown should ack to unblock PEL drain'
    );

    \Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator::resetForTest();
    \Swoolefy\Websocket\Cluster\ClusterConfig::setWebsocketOverride(null);
    echo "[OK] shouldAck serverUnavailable during shutdown\n";
}

/** 非法 JSON payload 应 ACK 丢弃，避免毒消息阻塞 Stream */
function testDeliverWithResultInvalidPayload(): void
{
    assertTrue(PushDeliveryWorker::shouldAckStreamPayload('not-json'), 'invalid json should ack discard');
    $result = PushDeliveryWorker::deliverWithResult('not-json');
    assertTrue($result->invalidPayload && $result->shouldAck(), 'invalid payload result');
    echo "[OK] deliverWithResult invalid payload\n";
}

/** 非 WS Worker 环境无 Server 时不 ACK，等待进程恢复后重试 */
function testDeliverWithResultServerUnavailable(): void
{
    $payload = PushMessage::encode(PushMessage::event(
        [['fd' => 1, 'conn_id' => 'ws:1']],
        'chat.message',
        ['msg' => 'hi'],
        'test'
    ));
    assertTrue(!PushDeliveryWorker::shouldAckStreamPayload($payload), 'no server should not ack');
    echo "[OK] deliverWithResult server unavailable\n";
}

/** 模拟 PushStreamConsumer：handler 返回 false 不 xAck，true 才 xAck */
function testStreamConsumerHandlerIntegration(): void
{
    $handler = static function (string $entryId, string $payload): bool {
        if ($payload === 'retry') {
            return false;
        }

        return true;
    };

    $entries = [['id' => '1-0', 'payload' => 'retry']];
    $redis = new class() extends NoopClusterRedisAdapter {
        public array $acked = [];

        public function xAck(string $key, string $group, array $entryIds): int
        {
            $this->acked = array_merge($this->acked, $entryIds);

            return count($entryIds);
        }
    };

    PushStreamConsumer::handleEntriesForTest($redis, 'stream', 'group', $entries, $handler);
    assertTrue($redis->acked === [], 'handler false should not xack');

    $entries = [['id' => '2-0', 'payload' => 'ok']];
    PushStreamConsumer::handleEntriesForTest($redis, 'stream', 'group', $entries, $handler);
    assertTrue($redis->acked === ['2-0'], 'handler true should xack');

    echo "[OK] stream consumer handler ack branch\n";
}

testShouldAckDelivered();
testShouldAckAllGoneOrSkipped();
testShouldNotAckFailed();
testShouldAckEmptyAndInvalid();
testShouldAckServerUnavailableDuringShutdown();
testDeliverWithResultInvalidPayload();
testDeliverWithResultServerUnavailable();
testStreamConsumerHandlerIntegration();

echo "All websocket push delivery ack tests passed.\n";
