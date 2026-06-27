<?php

namespace Swoolefy\Websocket\Metrics;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * WebSocket 内置可观测性指标（跨 Worker 共享 Swoole\Table）。
 *
 * | 指标 | 类型 | 说明 |
 * |------|------|------|
 * | ws_connections_total | gauge | 快照时本节点在线连接数 |
 * | ws_push_delivered | counter | 累计 push 成功 fd 数 |
 * | ws_push_failed | counter | 累计 push 失败 fd 数 / 投递不可用 |
 * | ws_join_denied_total | counter | 加组鉴权拒绝次数 |
 * | ws_push_dedup_skipped | counter | 去重跳过重复 msg_id 次数 |
 * | redis_stream_pending | gauge | 本节点 Stream PEL 堆积（XPENDING） |
 * | redis_stream_lag_ms | gauge | 最近观测到的推送消费延迟（ms） |
 *
 * 快照：`WebsocketMetrics::snapshot()`，可接入 sys_collector callback 或 Prometheus exporter。
 */
class WebsocketMetrics
{
    public const TABLE = 'table_websocket_metrics';

    private const ROW = '_global';

    private const FIELD_PUSH_DELIVERED = 'push_delivered';

    private const FIELD_PUSH_FAILED = 'push_failed';

    private const FIELD_JOIN_DENIED = 'join_denied';

    private const FIELD_PUSH_DEDUP_SKIPPED = 'push_dedup_skipped';

    private const FIELD_CONNECTIONS = 'connections_total';

    private const FIELD_STREAM_PENDING = 'stream_pending';

    private const FIELD_STREAM_LAG_MS = 'stream_lag_ms';

    public static function tableDefinitions(): array
    {
        return [
            self::TABLE => [
                'size' => 8,
                'fields' => [
                    [self::FIELD_PUSH_DELIVERED, 'int', 8],
                    [self::FIELD_PUSH_FAILED, 'int', 8],
                    [self::FIELD_JOIN_DENIED, 'int', 8],
                    [self::FIELD_PUSH_DEDUP_SKIPPED, 'int', 8],
                    [self::FIELD_CONNECTIONS, 'int', 8],
                    [self::FIELD_STREAM_PENDING, 'int', 8],
                    [self::FIELD_STREAM_LAG_MS, 'int', 8],
                ],
            ],
        ];
    }

    public static function isEnabled(): bool
    {
        $metrics = ClusterConfig::websocket()['metrics'] ?? [];

        return is_array($metrics) && !empty($metrics['enable']);
    }

    public static function refreshInterval(): int
    {
        $metrics = ClusterConfig::websocket()['metrics'] ?? [];
        if (!is_array($metrics)) {
            return 10;
        }

        return max(5, (int) ($metrics['refresh_interval'] ?? 10));
    }

    public static function bootRow(): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        if (!TableManager::exist(self::TABLE, self::ROW)) {
            TableManager::set(self::TABLE, self::ROW, [
                self::FIELD_PUSH_DELIVERED => 0,
                self::FIELD_PUSH_FAILED => 0,
                self::FIELD_JOIN_DENIED => 0,
                self::FIELD_PUSH_DEDUP_SKIPPED => 0,
                self::FIELD_CONNECTIONS => 0,
                self::FIELD_STREAM_PENDING => 0,
                self::FIELD_STREAM_LAG_MS => 0,
            ]);
        }
    }

    public static function recordPushDelivery(PushDeliveryResult $result): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        self::bootRow();
        if ($result->delivered > 0) {
            TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_DELIVERED, $result->delivered);
        }

        $failed = $result->failed + ($result->serverUnavailable ? 1 : 0);
        if ($failed > 0) {
            TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_FAILED, $failed);
        }
    }

    public static function recordJoinDenied(): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        self::bootRow();
        TableManager::incr(self::TABLE, self::ROW, self::FIELD_JOIN_DENIED);
    }

    public static function recordPushDedupSkipped(int $count = 1): void
    {
        if (!self::isEnabled() || !self::tableReady() || $count <= 0) {
            return;
        }

        self::bootRow();
        TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_DEDUP_SKIPPED, $count);
    }

    /** 观测单条 Stream 消息的消费延迟（基于 PushMessage.ts） */
    public static function observeStreamLagMs(int $lagMs): void
    {
        if (!self::isEnabled() || !self::tableReady() || $lagMs < 0) {
            return;
        }

        self::bootRow();
        TableManager::set(self::TABLE, self::ROW, [
            self::FIELD_STREAM_LAG_MS => $lagMs,
        ]);
    }

    /**
     * 刷新 gauge 类指标（worker 0 定时调用）。
     */
    public static function refreshGauges(): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        self::bootRow();
        TableManager::set(self::TABLE, self::ROW, [
            self::FIELD_CONNECTIONS => WebsocketConnectionManager::countLocalConnections(),
        ]);

        if (!ClusterConfig::isEnabled() || !ClusterConfig::usesPushStreams()) {
            return;
        }

        try {
            $streamKey = ClusterConfig::pushStreamKeyForServer(ClusterNodeIdentity::getServerId());
            $group = ClusterConfig::pushStreamGroup();
            ClusterRedisClient::execute(static function ($redis) use ($streamKey, $group) {
                $pending = (int) $redis->xPendingCount($streamKey, $group);
                TableManager::set(self::TABLE, self::ROW, [
                    self::FIELD_STREAM_PENDING => $pending,
                ]);
            });
        } catch (\Throwable $throwable) {
            // Redis 不可用时保留上次 gauge
        }
    }

    /**
     * @return array<string, int|string>
     */
    public static function snapshot(): array
    {
        if (!self::isEnabled()) {
            return ['metrics_enabled' => 0];
        }

        self::refreshGauges();

        $row = self::tableReady() ? (TableManager::get(self::TABLE, self::ROW) ?: []) : [];

        return [
            'metrics_enabled' => 1,
            'server_id' => ClusterConfig::isEnabled() ? ClusterNodeIdentity::getServerId() : '',
            'ws_connections_total' => (int) ($row[self::FIELD_CONNECTIONS] ?? WebsocketConnectionManager::countLocalConnections()),
            'ws_push_delivered' => (int) ($row[self::FIELD_PUSH_DELIVERED] ?? 0),
            'ws_push_failed' => (int) ($row[self::FIELD_PUSH_FAILED] ?? 0),
            'ws_join_denied_total' => (int) ($row[self::FIELD_JOIN_DENIED] ?? 0),
            'ws_push_dedup_skipped' => (int) ($row[self::FIELD_PUSH_DEDUP_SKIPPED] ?? 0),
            'redis_stream_pending' => (int) ($row[self::FIELD_STREAM_PENDING] ?? 0),
            'redis_stream_lag_ms' => (int) ($row[self::FIELD_STREAM_LAG_MS] ?? 0),
            'timestamp' => time(),
        ];
    }

    /** 单测重置 */
    public static function resetForTest(): void
    {
        if (!self::tableReady()) {
            return;
        }

        TableManager::set(self::TABLE, self::ROW, [
            self::FIELD_PUSH_DELIVERED => 0,
            self::FIELD_PUSH_FAILED => 0,
            self::FIELD_JOIN_DENIED => 0,
            self::FIELD_PUSH_DEDUP_SKIPPED => 0,
            self::FIELD_CONNECTIONS => 0,
            self::FIELD_STREAM_PENDING => 0,
            self::FIELD_STREAM_LAG_MS => 0,
        ]);
    }

    private static function tableReady(): bool
    {
        return TableManager::isExistTable(self::TABLE);
    }
}
