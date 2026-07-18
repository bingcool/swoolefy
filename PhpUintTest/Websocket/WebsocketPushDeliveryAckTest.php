<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket;

use PhpUintTest\TestCase;
use PhpUintTest\Websocket\Support\NoopClusterRedisAdapter;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushDeliveryWorker;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Cluster\PushStreamConsumer;
use Swoolefy\Websocket\Cluster\WebsocketShutdownCoordinator;

/**
 * Streams 推送 XACK 智能决策单元测试。
 */
final class WebsocketPushDeliveryAckTest extends TestCase
{
    /** @param list<string> $outcomes */
    private function resultWithOutcomes(array $outcomes): PushDeliveryResult
    {
        $result = new PushDeliveryResult();
        foreach ($outcomes as $outcome) {
            $result->recordOutcome($outcome);
        }

        return $result;
    }

    public function testShouldAckDelivered(): void
    {
        $this->assertTrue($this->resultWithOutcomes(['delivered'])->shouldAck());
        $this->assertTrue($this->resultWithOutcomes(['delivered', 'gone', 'failed'])->shouldAck());
    }

    public function testShouldAckAllGoneOrSkipped(): void
    {
        $this->assertTrue($this->resultWithOutcomes(['gone', 'gone'])->shouldAck());
        $this->assertTrue($this->resultWithOutcomes(['skipped'])->shouldAck());
        $this->assertTrue($this->resultWithOutcomes(['gone', 'skipped'])->shouldAck());
    }

    public function testShouldNotAckFailed(): void
    {
        $this->assertFalse($this->resultWithOutcomes(['failed'])->shouldAck());
        $this->assertFalse($this->resultWithOutcomes(['gone', 'failed'])->shouldAck());
    }

    public function testShouldAckEmptyAndInvalid(): void
    {
        $this->assertTrue((new PushDeliveryResult())->shouldAck());
        $this->assertTrue(PushDeliveryResult::invalidPayload()->shouldAck());
        $this->assertTrue(PushDeliveryResult::duplicateSkipped()->shouldAck());
        $this->assertFalse(PushDeliveryResult::serverUnavailable()->shouldAck());
    }

    public function testShouldAckServerUnavailableDuringShutdown(): void
    {
        ClusterConfig::setWebsocketOverride([
            'graceful_shutdown' => [
                'enable' => true,
                'drain_timeout' => 5,
            ],
        ]);
        WebsocketShutdownCoordinator::useMemoryFlagForTest();
        WebsocketShutdownCoordinator::setShuttingDownForTest(true);

        try {
            $this->assertTrue(PushDeliveryResult::serverUnavailable()->shouldAck());
        } finally {
            WebsocketShutdownCoordinator::resetForTest();
            ClusterConfig::setWebsocketOverride(null);
        }
    }

    public function testDeliverWithResultInvalidPayload(): void
    {
        $this->assertTrue(PushDeliveryWorker::shouldAckStreamPayload('not-json'));
        $result = PushDeliveryWorker::deliverWithResult('not-json');
        $this->assertTrue($result->invalidPayload);
        $this->assertTrue($result->shouldAck());
    }

    public function testDeliverWithResultServerUnavailable(): void
    {
        $payload = PushMessage::encode(PushMessage::event(
            [['fd' => 1, 'conn_id' => 'ws:1']],
            'chat.message',
            ['msg' => 'hi'],
            'test'
        ));
        $this->assertFalse(PushDeliveryWorker::shouldAckStreamPayload($payload));
    }

    public function testStreamConsumerHandlerIntegration(): void
    {
        $handler = static function (string $entryId, string $payload): bool {
            return $payload !== 'retry';
        };

        $redis = new class() extends NoopClusterRedisAdapter {
            /** @var list<string> */
            public array $acked = [];

            public function xAck(string $key, string $group, array $entryIds): int
            {
                $this->acked = array_merge($this->acked, $entryIds);

                return count($entryIds);
            }
        };

        PushStreamConsumer::handleEntriesForTest(
            $redis,
            'stream',
            'group',
            [['id' => '1-0', 'payload' => 'retry']],
            $handler
        );
        $this->assertSame([], $redis->acked);

        PushStreamConsumer::handleEntriesForTest(
            $redis,
            'stream',
            'group',
            [['id' => '2-0', 'payload' => 'ok']],
            $handler
        );
        $this->assertSame(['2-0'], $redis->acked);
    }
}
