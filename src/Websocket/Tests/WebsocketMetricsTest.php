<?php
/**
 * WebSocket metrics & trace propagation tests.
 *
 * Run: php src/Websocket/Tests/WebsocketMetricsTest.php
 */

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Metrics\WebsocketMetrics;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testPushMessageHasTraceId(): void
{
    $message = PushMessage::event(
        [['fd' => 1, 'conn_id' => 'ws:1']],
        'chat.message',
        ['msg' => 'hi'],
        'test',
        'trace-fixed-001'
    );
    assertTrue(($message['trace_id'] ?? '') === 'trace-fixed-001', 'trace_id should be preserved');

    $decoded = PushMessage::decode(PushMessage::encode($message));
    assertTrue(is_array($decoded) && ($decoded['trace_id'] ?? '') === 'trace-fixed-001', 'trace_id roundtrip');
    echo "[OK] push message trace_id\n";
}

function testTraceContextExtract(): void
{
    WebsocketTraceContext::apply('trace-ctx-abc');
    assertTrue(
        WebsocketTraceContext::extractFromMessage(['trace_id' => 'trace-msg-xyz']) === 'trace-msg-xyz',
        'extract from message'
    );
    echo "[OK] trace context extract\n";
}

function testMetricsCountersWithTable(): void
{
    if (!extension_loaded('swoole')) {
        echo "[SKIP] metrics table tests (swoole extension unavailable)\n";

        return;
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
    assertTrue(($snapshot['ws_push_delivered'] ?? 0) === 2, 'push delivered counter');
    assertTrue(($snapshot['ws_push_failed'] ?? 0) === 2, 'push failed counter');
    assertTrue(($snapshot['ws_join_denied_total'] ?? 0) === 1, 'join denied counter');
    assertTrue(($snapshot['redis_stream_lag_ms'] ?? 0) === 1500, 'stream lag gauge');

    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] metrics counters with table\n";
}

function testMetricsDisabledSnapshot(): void
{
    ClusterConfig::setWebsocketOverride([
        'metrics' => ['enable' => false],
    ]);
    $snapshot = WebsocketMetrics::snapshot();
    assertTrue(($snapshot['metrics_enabled'] ?? 1) === 0, 'metrics disabled snapshot');
    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] metrics disabled snapshot\n";
}

testPushMessageHasTraceId();
testTraceContextExtract();
testMetricsCountersWithTable();
testMetricsDisabledSnapshot();

echo "All websocket metrics tests passed.\n";
