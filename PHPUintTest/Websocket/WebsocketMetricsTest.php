<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use RuntimeException;
use Swoolefy\Core\Table\TableManager;
use PHPUintTest\TestCase;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Metrics\WebsocketMetrics;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;

/**
 * WebSocket 可观测性指标与 trace 传播单元测试。
 */
final class WebsocketMetricsTest extends TestCase
{
    protected function tearDown(): void
    {
        ClusterConfig::setWebsocketOverride(null);
        parent::tearDown();
    }

    public function testPushMessageHasTraceId(): void
    {
        $message = PushMessage::event(
            [['fd' => 1, 'conn_id' => 'ws:1']],
            'chat.message',
            ['msg' => 'hi'],
            'test',
            'trace-fixed-001'
        );
        $this->assertSame('trace-fixed-001', $message['trace_id'] ?? '');

        $decoded = PushMessage::decode(PushMessage::encode($message));
        $this->assertIsArray($decoded);
        $this->assertSame('trace-fixed-001', $decoded['trace_id'] ?? '');
    }

    public function testTraceContextExtract(): void
    {
        WebsocketTraceContext::apply('trace-ctx-abc');
        $this->assertSame(
            'trace-msg-xyz',
            WebsocketTraceContext::extractFromMessage(['trace_id' => 'trace-msg-xyz'])
        );
    }

    public function testMetricsCountersWithTable(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('swoole extension unavailable');
        }

        ClusterConfig::setWebsocketOverride([
            'metrics' => ['enable' => true, 'refresh_interval' => 10],
        ]);

        TableManager::createTable(WebsocketMetrics::tableDefinitions());
        WebsocketMetrics::resetForTest();

        $delivered = new PushDeliveryResult();
        $delivered->recordOutcome('delivered');
        $delivered->recordOutcome('delivered');
        WebsocketMetrics::recordPushDelivery($delivered);

        $failed = new PushDeliveryResult();
        $failed->recordOutcome('failed');
        $failed->recordFailed();
        WebsocketMetrics::recordPushDelivery($failed);

        WebsocketMetrics::recordJoinDenied();
        WebsocketMetrics::observeStreamLagMs(1500);

        $snapshot = WebsocketMetrics::snapshot();
        $this->assertSame(2, $snapshot['ws_push_delivered'] ?? 0);
        $this->assertSame(2, $snapshot['ws_push_failed'] ?? 0);
        $this->assertSame(1, $snapshot['ws_join_denied_total'] ?? 0);
        $this->assertSame(1500, $snapshot['redis_stream_lag_ms'] ?? 0);
    }

    public function testMetricsSnapshotHook(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('swoole extension unavailable');
        }

        $calls = 0;
        $seenPrevious = false;
        ClusterConfig::setWebsocketOverride([
            'metrics' => [
                'enable' => true,
                'refresh_interval' => 10,
                'on_snapshot' => static function (array $current, ?array $previous) use (&$calls, &$seenPrevious): void {
                    $calls++;
                    if (is_array($previous) && isset($previous['timestamp'])) {
                        $seenPrevious = true;
                    }
                    if (($current['ws_push_delivered'] ?? 0) > 0) {
                        throw new RuntimeException('hook boom');
                    }
                },
            ],
        ]);

        TableManager::createTable(WebsocketMetrics::tableDefinitions());
        WebsocketMetrics::resetForTest();

        $first = WebsocketMetrics::snapshot();
        $this->assertSame(1, $first['metrics_enabled'] ?? 0);

        $delivered = new PushDeliveryResult();
        $delivered->recordOutcome('delivered');
        WebsocketMetrics::recordPushDelivery($delivered);

        $second = WebsocketMetrics::snapshot();
        $this->assertSame(1, $second['ws_push_delivered'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertTrue($seenPrevious);
    }

    public function testMetricsDisabledSnapshot(): void
    {
        ClusterConfig::setWebsocketOverride([
            'metrics' => ['enable' => false],
        ]);
        $snapshot = WebsocketMetrics::snapshot();
        $this->assertSame(0, $snapshot['metrics_enabled'] ?? 1);
    }
}
