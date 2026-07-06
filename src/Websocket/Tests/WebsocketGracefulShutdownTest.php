<?php
/**
 * 优雅停机（WebsocketShutdownCoordinator / PushStreamConsumer drain）单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketGracefulShutdownTest.php
 */

use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator;
use Swoolefy\Websocket\Tests\Support\NoopClusterRedisAdapter;

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
    $redis = new class($reads) extends NoopClusterRedisAdapter {
        private int $reads;

        public function __construct(int &$reads)
        {
            $this->reads = &$reads;
        }

        public function xReadGroup(string $group, string $consumer, string $streamKey, int $count, int $blockMs, string $id = '>'): array
        {
            $this->reads++;

            return $this->reads >= 2 ? [] : [['id' => '1-0', 'payload' => 'ok']];
        }
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
    $redis = new class($pending, $acked) extends NoopClusterRedisAdapter {
        private int $pending;

        /** @var array<int, string> */
        private array $acked;

        public function __construct(int &$pending, array &$acked)
        {
            $this->pending = &$pending;
            $this->acked = &$acked;
        }

        public function xReadGroup(string $group, string $consumer, string $streamKey, int $count, int $blockMs, string $id = '>'): array
        {
            if ($id === '0' && $this->pending > 0) {
                $this->pending = 0;

                return [['id' => '9-0', 'payload' => 'pending']];
            }

            return [];
        }

        public function xAck(string $key, string $group, array $entryIds): int
        {
            $this->acked = array_merge($this->acked, $entryIds);

            return count($entryIds);
        }

        public function xPendingCount(string $key, string $group): int
        {
            return $this->pending;
        }
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

/** onBeforeShutdown 应标记停机但不重复 drain 逻辑冲突 */
function testOnBeforeShutdownMarksFlag(): void
{
    bootGracefulConfig();
    WebsocketShutdownCoordinator::onBeforeShutdown(new \Swoole\Server('127.0.0.1', 0));
    assertTrue(WebsocketShutdownCoordinator::isShuttingDown(), 'before shutdown should mark flag');
    assertTrue(WebsocketShutdownCoordinator::shouldRejectNewConnections(), 'should reject after mark');

    teardownGracefulConfig();
    echo "[OK] onBeforeShutdown marks flag\n";
}

testShouldRejectNewConnections();
testRunControlledStopsWhenShouldContinueFalse();
testDrainProcessesPending();
testOnBeforeShutdownMarksFlag();

echo "All websocket graceful shutdown tests passed.\n";
