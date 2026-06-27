<?php
/**
 * 优雅停机（WebsocketShutdownCoordinator / PushStreamConsumer drain）单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketGracefulShutdownTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('APP_NAME', 'WebsocketService');

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 启用 graceful_shutdown 配置 */
function bootGracefulConfig(): void
{
    ClusterConfig::setWebsocketOverride([
        'graceful_shutdown' => [
            'enable' => true,
            'drain_timeout' => 5,
            'reject_reason' => 'draining',
        ],
        'cluster' => [
            'enable' => true,
            'server_id' => 'ws-graceful-test',
            'push' => [
                'transport' => 'streams',
                'stream_block_ms' => 5000,
            ],
        ],
    ]);
    WebsocketShutdownCoordinator::useMemoryFlagForTest();
}

/** 清理单测注入 */
function teardownGracefulConfig(): void
{
    ClusterConfig::setWebsocketOverride(null);
    WebsocketShutdownCoordinator::resetForTest();
}

/** 停机标志开启后应拒绝新连接 */
function testShouldRejectNewConnections(): void
{
    bootGracefulConfig();
    assertTrue(!WebsocketShutdownCoordinator::shouldRejectNewConnections(), 'not shutting down yet');

    WebsocketShutdownCoordinator::setShuttingDownForTest(true);
    assertTrue(WebsocketShutdownCoordinator::shouldRejectNewConnections(), 'should reject when draining');
    assertTrue(WebsocketShutdownCoordinator::shouldStopConsuming(), 'consumer should stop');

    teardownGracefulConfig();
    echo "[OK] should reject new connections\n";
}

/** runControlled 在 shouldContinue 返回 false 后退出循环 */
function testRunControlledStopsWhenShouldContinueFalse(): void
{
    bootGracefulConfig();

    $reads = 0;
    $redis = new class($reads) implements ClusterRedisAdapterInterface {
        private int $reads;

        public function __construct(int &$reads)
        {
            $this->reads = &$reads;
        }

        public function hMSet(string $key, array $data): void {}
        public function hSet(string $key, string $field, $value): void {}
        public function hGetAll(string $key) { return []; }
        public function hGetAllMany(array $keys): array { return []; }
        public function expire(string $key, int $ttl): void {}
        public function del(string $key): void {}
        public function setEx(string $key, int $ttl, string $value): void {}
        public function exists(string $key): bool { return false; }
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
        public function xReadGroup(string $group, string $consumer, string $streamKey, int $count, int $blockMs, string $id = '>'): array {
            $this->reads++;

            return $this->reads >= 2 ? [] : [['id' => '1-0', 'payload' => 'ok']];
        }
        public function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, string $start, int $count): array {
            return ['0-0', []];
        }
        public function xAck(string $key, string $group, array $entryIds): int { return count($entryIds); }
        public function xPendingCount(string $key, string $group): int { return 0; }
        public function xAddMany(array $items, int $maxLen = 0): void {}
        public function ping() { return true; }
        public function close(): void {}
    };

    $iterations = 0;
    PushStreamConsumer::runControlledOnAdapterForTest(
        $redis,
        'stream',
        'group',
        'consumer-1',
        static fn (string $entryId, string $payload): bool => true,
        static function () use (&$iterations): bool {
            $iterations++;

            return $iterations < 3;
        }
    );

    assertTrue($iterations >= 3, 'shouldContinue invoked until loop exits');
    assertTrue($reads >= 1, 'should read at least once');

    teardownGracefulConfig();
    echo "[OK] runControlled stops when shouldContinue false\n";
}

/** drain 应处理 PEL pending 并在 xPendingCount=0 时结束 */
function testDrainProcessesPending(): void
{
    bootGracefulConfig();

    $pending = 1;
    $acked = [];
    $redis = new class($pending, $acked) implements ClusterRedisAdapterInterface {
        private int $pending;

        /** @var array<int, string> */
        private array $acked;

        public function __construct(int &$pending, array &$acked)
        {
            $this->pending = &$pending;
            $this->acked = &$acked;
        }

        public function hMSet(string $key, array $data): void {}
        public function hSet(string $key, string $field, $value): void {}
        public function hGetAll(string $key) { return []; }
        public function hGetAllMany(array $keys): array { return []; }
        public function expire(string $key, int $ttl): void {}
        public function del(string $key): void {}
        public function setEx(string $key, int $ttl, string $value): void {}
        public function exists(string $key): bool { return false; }
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
        public function xReadGroup(string $group, string $consumer, string $streamKey, int $count, int $blockMs, string $id = '>'): array {
            if ($id === '0' && $this->pending > 0) {
                $this->pending = 0;

                return [['id' => '9-0', 'payload' => 'pending']];
            }

            return [];
        }
        public function xAutoClaim(string $key, string $group, string $consumer, int $minIdleMs, string $start, int $count): array {
            return ['0-0', []];
        }
        public function xAck(string $key, string $group, array $entryIds): int {
            $this->acked = array_merge($this->acked, $entryIds);

            return count($entryIds);
        }
        public function xPendingCount(string $key, string $group): int { return $this->pending; }
        public function xAddMany(array $items, int $maxLen = 0): void {}
        public function ping() { return true; }
        public function close(): void {}
    };

    $processed = PushStreamConsumer::drainOnAdapterForTest(
        $redis,
        'stream',
        'group',
        'consumer-1',
        static fn (string $entryId, string $payload): bool => true,
        time() + 2
    );

    assertTrue($processed >= 1, 'should process pending entry');
    assertTrue($acked === ['9-0'], 'pending entry should be acked');

    teardownGracefulConfig();
    echo "[OK] drain processes pending\n";
}

testShouldRejectNewConnections();
testRunControlledStopsWhenShouldContinueFalse();
testDrainProcessesPending();

echo "All websocket graceful shutdown tests passed.\n";
