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
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;

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
    assertTrue($r->shouldAck(), 'gone+skipped should ack');
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

/** 空 targets / 非法 payload ACK 丢弃；server 不可用不 ACK */
function testShouldAckEmptyAndInvalid(): void
{
    assertTrue((new PushDeliveryResult())->shouldAck(), 'empty targets should ack');
    assertTrue(PushDeliveryResult::invalidPayload()->shouldAck(), 'invalid payload should ack discard');
    assertTrue(!PushDeliveryResult::serverUnavailable()->shouldAck(), 'server unavailable should not ack');
    echo "[OK] shouldAck empty/invalid/server\n";
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
    $redis = new class() implements ClusterRedisAdapterInterface {
        public array $acked = [];
        public function hMSet(string $key, array $data): void {}
        public function hSet(string $key, string $field, $value): void {}
        public function hGetAll(string $key) { return []; }
        public function hGetAllMany(array $keys): array { return []; }
        public function expire(string $key, int $ttl): void {}
        public function del(string $key): void {}
        public function sAdd(string $key, string $member): void {}
        public function sRem(string $key, string $member): void {}
        public function sMembers(string $key) { return []; }
        public function sCard(string $key): int { return 0; }
        public function zAdd(string $key, $score, string $member): void {}
        public function zRem(string $key, string $member): void {}
        public function zRangeByScore(string $key, string $start, string $end) { return []; }
        public function publish(string $channel, string $message) { return 0; }
        public function publishMany(array $items): void {}
        public function rPush(string $key, string $value): void {}
        public function brPop(string $key, int $timeoutSeconds): ?string { return null; }
        public function xAdd(string $key, array $fields, int $maxLen = 0): string { return '0-1'; }
        public function xGroupCreate(string $key, string $group, bool $mkStream = true): void {}
        public function xReadGroup(string $group, string $consumer, string $streamKey, int $count, int $blockMs, string $id = '>'): array { return []; }
        public function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, string $start, int $count): array { return ['0-0', []]; }
        public function xAck(string $key, string $group, array $entryIds): int {
            $this->acked = array_merge($this->acked, $entryIds);
            return count($entryIds);
        }
        public function xPendingCount(string $key, string $group): int { return 0; }
        public function xAddMany(array $items, int $maxLen = 0): void {}
        public function ping() { return true; }
        public function close(): void {}
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
testDeliverWithResultInvalidPayload();
testDeliverWithResultServerUnavailable();
testStreamConsumerHandlerIntegration();

echo "All websocket push delivery ack tests passed.\n";
