<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use PHPUintTest\Websocket\Support\NoopClusterRedisAdapter;
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
        // 全成功 + 不可重试 gone：可 ACK
        $this->assertTrue($this->resultWithOutcomes(['delivered', 'gone'])->shouldAck());
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
        // 部分成功仍有可重试 failed 时不得整条 ACK
        $this->assertFalse($this->resultWithOutcomes(['delivered', 'gone', 'failed'])->shouldAck());
        $this->assertSame(1, $this->resultWithOutcomes(['delivered', 'failed'])->retryableCount());
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
        // deliverWithResult 会写协程 Context（trace_id），须在协程内调用
        $acked = null;
        \Swoole\Coroutine\run(function () use (&$acked): void {
            $payload = PushMessage::encode(PushMessage::event(
                [['fd' => 1, 'conn_id' => 'ws:1']],
                'chat.message',
                ['msg' => 'hi'],
                'test'
            ));
            $acked = PushDeliveryWorker::shouldAckStreamPayload($payload);
        });
        $this->assertFalse($acked);
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
