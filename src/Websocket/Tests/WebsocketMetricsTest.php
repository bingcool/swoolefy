<?php
/**
 * WebSocket 可观测性指标与 trace 传播单元测试。
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

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** PushMessage 应保留显式传入的 trace_id，编解码往返不丢 */
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

/** 消费端应从 PushMessage.trace_id 提取 trace，而非协程上下文 */
function testTraceContextExtract(): void
{
    WebsocketTraceContext::apply('trace-ctx-abc');
    assertTrue(
        WebsocketTraceContext::extractFromMessage(['trace_id' => 'trace-msg-xyz']) === 'trace-msg-xyz',
        'extract from message'
    );
    echo "[OK] trace context extract\n";
}

/** Swoole Table 跨 Worker 累计：push_delivered/failed、join_denied、stream_lag */
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

/** metrics.on_snapshot 钩子应收到 current + previous，且异常不影响 snapshot */
function testMetricsSnapshotHook(): void
{
    if (!extension_loaded('swoole')) {
        echo "[SKIP] metrics snapshot hook (swoole extension unavailable)\n";

        return;
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

    // 首次 snapshot：previous 为 null
    $first = WebsocketMetrics::snapshot();
    assertTrue(($first['metrics_enabled'] ?? 0) === 1, 'first snapshot should work');

    $delivered = new PushDeliveryResult();
    $delivered->recordOutcome('delivered');
    WebsocketMetrics::recordPushDelivery($delivered);

    // 第二次 snapshot：hook 抛异常也不能影响返回
    $second = WebsocketMetrics::snapshot();
    assertTrue(($second['ws_push_delivered'] ?? 0) === 1, 'second snapshot should include delivered');
    assertTrue($calls >= 2, 'hook should be called on each snapshot');
    assertTrue($seenPrevious, 'hook should receive previous snapshot');

    ClusterConfig::setWebsocketOverride(null);
    echo "[OK] metrics snapshot hook\n";
}

/** metrics.enable=false 时 snapshot 仅返回 metrics_enabled=0 */
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
testMetricsSnapshotHook();
testMetricsDisabledSnapshot();

echo "All websocket metrics tests passed.\n";
