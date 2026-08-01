<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use PHPUintTest\Websocket\Support\NoopClusterRedisAdapter;
use PHPUintTest\Websocket\Support\WebsocketAppConstants;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator;

/**
 * 优雅停机（WebsocketShutdownCoordinator / PushStreamConsumer drain）单元测试。
 */
final class WebsocketGracefulShutdownTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
    }

    protected function tearDown(): void
    {
        $this->teardownGracefulConfig();
        parent::tearDown();
    }

    private function bootGracefulConfig(): void
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

    private function teardownGracefulConfig(): void
    {
        ClusterConfig::setWebsocketOverride(null);
        WebsocketShutdownCoordinator::resetForTest();
    }

    public function testShouldRejectNewConnections(): void
    {
        $this->bootGracefulConfig();
        $this->assertFalse(WebsocketShutdownCoordinator::shouldRejectNewConnections());

        WebsocketShutdownCoordinator::setShuttingDownForTest(true);
        $this->assertTrue(WebsocketShutdownCoordinator::shouldRejectNewConnections());
        $this->assertTrue(WebsocketShutdownCoordinator::shouldStopConsuming());
    }

    public function testRunControlledStopsWhenShouldContinueFalse(): void
    {
        $this->bootGracefulConfig();

        $reads = 0;
        $redis = new class($reads) extends NoopClusterRedisAdapter {
            private int $reads;

            public function __construct(int &$reads)
            {
                $this->reads = &$reads;
            }

            public function xReadGroup(
                string $group,
                string $consumer,
                string $streamKey,
                int $count,
                int $blockMs,
                string $id = '>'
            ): array {
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

        $this->assertGreaterThanOrEqual(3, $iterations);
        $this->assertGreaterThanOrEqual(1, $reads);
    }

    public function testDrainProcessesPending(): void
    {
        $this->bootGracefulConfig();

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

            public function xReadGroup(
                string $group,
                string $consumer,
                string $streamKey,
                int $count,
                int $blockMs,
                string $id = '>'
            ): array {
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

        $this->assertGreaterThanOrEqual(1, $processed);
        $this->assertSame(['9-0'], $acked);
    }

    public function testOnBeforeShutdownMarksFlag(): void
    {
        $this->bootGracefulConfig();
        WebsocketShutdownCoordinator::onBeforeShutdown(new \Swoole\Server('127.0.0.1', 0));
        $this->assertTrue(WebsocketShutdownCoordinator::isShuttingDown());
        $this->assertTrue(WebsocketShutdownCoordinator::shouldRejectNewConnections());
    }

    public function testRecommendedStopTimeouts(): void
    {
        $this->bootGracefulConfig();
        $force = WebsocketShutdownCoordinator::recommendedForceKillTimeout(10);
        $total = WebsocketShutdownCoordinator::recommendedStopTimeout(10);
        $this->assertGreaterThanOrEqual(5 + 5, $force);
        $this->assertGreaterThanOrEqual(5 + 10 + 5, $total);
        // assertGreaterThan($expected, $actual) ⇒ $actual > $expected
        $this->assertGreaterThan($force, $total);
    }
}
