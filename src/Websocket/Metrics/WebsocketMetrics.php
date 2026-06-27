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
 * ## 存储模型
 *
 * - 表名 `table_websocket_metrics`，单行 `_global`，各 Worker 通过 TableManager::incr 累加 counter
 * - **counter**：进程生命周期内单调递增（push 成功/失败、鉴权拒绝、去重跳过）
 * - **gauge**：快照时刷新（连接数、Stream PEL、消费延迟）
 *
 * ## 指标一览
 *
 * | 快照字段 | 类型 | 说明 |
 * |----------|------|------|
 * | ws_connections_total | gauge | 本节点在线连接数（本地 Table 计数） |
 * | ws_push_delivered | counter | 累计 push 成功 fd 数 |
 * | ws_push_failed | counter | 累计 push 失败 fd 数 + server 不可用次数 |
 * | ws_join_denied_total | counter | 加组鉴权拒绝次数 |
 * | ws_push_dedup_skipped | counter | PushDedupStore 去重跳过重复 msg_id 次数 |
 * | redis_stream_pending | gauge | 本节点 push Stream 的 PEL 堆积（XPENDING） |
 * | redis_stream_lag_ms | gauge | 最近一条 Stream 消息的消费延迟（now - PushMessage.ts） |
 *
 * **不计入 failed**：gone（fd 已断开）、skipped（enricher 跳过）——属正常业务结果，非故障。
 *
 * ## 采集时机
 *
 * | 方法 | 调用方 |
 * |------|--------|
 * | recordPushDelivery | PushDeliveryHandler、PushDeliveryWorker |
 * | recordPushDedupSkipped | PushDeliveryHandler（XAUTOCLAIM 重投去重） |
 * | recordJoinDenied | WebsocketConnectionManager::joinGroup |
 * | observeStreamLagMs | PushDeliveryWorker（Stream 消费时） |
 * | refreshGauges | worker 0 定时 tick（WebsocketServer） |
 *
 * ## 配置（Config/websocket.php → metrics）
 *
 * - `enable`：总开关，false 时所有 record* 为 no-op，snapshot 返回 metrics_enabled=0
 * - `refresh_interval`：gauge 刷新间隔秒数，最小 5，默认 10
 *
 * 快照：`WebsocketMetrics::snapshot()`，可接入 sys_collector callback 或 Prometheus exporter。
 *
 * @see WebsocketServer  worker 0 注册 Table 与定时 refreshGauges
 * @see PushDeliveryResult  recordPushDelivery 的计数来源
 */
class WebsocketMetrics
{
    /** Swoole\Table 表名，在 WebsocketServer 启动时与连接表一并创建 */
    public const TABLE = 'table_websocket_metrics';

    /** 全局单行 key，所有 Worker 共享同一行累加 counter */
    private const ROW = '_global';

    /** @var string Table 字段：累计 push 成功 fd 数 */
    private const FIELD_PUSH_DELIVERED = 'push_delivered';

    /** @var string Table 字段：累计 push 失败 + server 不可用 */
    private const FIELD_PUSH_FAILED = 'push_failed';

    /** @var string Table 字段：加组鉴权拒绝次数 */
    private const FIELD_JOIN_DENIED = 'join_denied';

    /** @var string Table 字段：msg_id 去重跳过次数 */
    private const FIELD_PUSH_DEDUP_SKIPPED = 'push_dedup_skipped';

    /** @var string Table 字段：gauge，本节点在线连接数 */
    private const FIELD_CONNECTIONS = 'connections_total';

    /** @var string Table 字段：gauge，Stream 消费者 PEL 待 ACK 条数 */
    private const FIELD_STREAM_PENDING = 'stream_pending';

    /** @var string Table 字段：gauge，最近观测到的 Stream 消费延迟（毫秒） */
    private const FIELD_STREAM_LAG_MS = 'stream_lag_ms';

    /** Table 定义，由 WebsocketServer 合并进全局 Table 配置 */
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

    /** 是否开启指标采集（Config/websocket.php → metrics.enable） */
    public static function isEnabled(): bool
    {
        $metrics = ClusterConfig::websocket()['metrics'] ?? [];

        return is_array($metrics) && !empty($metrics['enable']);
    }

    /** worker 0 刷新 gauge 的 tick 间隔（秒），最小 5 */
    public static function refreshInterval(): int
    {
        $metrics = ClusterConfig::websocket()['metrics'] ?? [];
        if (!is_array($metrics)) {
            return 10;
        }

        return max(5, (int) ($metrics['refresh_interval'] ?? 10));
    }

    /**
     * 初始化全局指标行（各 Worker Start 时调用一次）。
     *
     * 仅当行不存在时写入零值，避免覆盖其他 Worker 已累加的 counter。
     */
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

    /**
     * 记录一次集群/本地推送投递结果。
     *
     * - delivered：累加 PushDeliveryResult::delivered（实际 server->push 成功 fd 数）
     * - failed：累加 failed + serverUnavailable（后者表示 Worker 未就绪，PEL 会重试）
     * - gone / skipped 不计入（连接已断开或业务跳过，非故障指标）
     */
    public static function recordPushDelivery(PushDeliveryResult $result): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        self::bootRow();
        if ($result->delivered > 0) {
            TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_DELIVERED, $result->delivered);
        }

        // serverUnavailable 单独 +1，便于区分「fd push 失败」与「消费进程无 Server 实例」
        $failed = $result->failed + ($result->serverUnavailable ? 1 : 0);
        if ($failed > 0) {
            TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_FAILED, $failed);
        }
    }

    /** 加组鉴权被拒绝时 +1（WebsocketConnectionManager::joinGroup） */
    public static function recordJoinDenied(): void
    {
        if (!self::isEnabled() || !self::tableReady()) {
            return;
        }

        self::bootRow();
        TableManager::incr(self::TABLE, self::ROW, self::FIELD_JOIN_DENIED);
    }

    /**
     * msg_id 去重命中时 +N（PushDedupStore，防 XAUTOCLAIM 重复投递）。
     *
     * 高值通常表示 PEL 重投频繁或 dedup TTL 过短。
     */
    public static function recordPushDedupSkipped(int $count = 1): void
    {
        if (!self::isEnabled() || !self::tableReady() || $count <= 0) {
            return;
        }

        self::bootRow();
        TableManager::incr(self::TABLE, self::ROW, self::FIELD_PUSH_DEDUP_SKIPPED, $count);
    }

    /**
     * 观测单条 Stream 消息的消费延迟（覆盖写入，非累加）。
     *
     * 基于 PushMessage.ts 与当前时间差；PushDeliveryWorker 每消费一条更新一次。
     * 用于发现推送消费进程积压或 Worker 阻塞。
     */
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
     * 刷新 gauge 类指标（worker 0 定时调用，间隔见 refreshInterval）。
     *
     * 1. ws_connections_total ← 本地 Swoole\Table 连接数
     * 2. redis_stream_pending ← XPENDING（仅 cluster + streams 模式）
     *
     * Redis 不可用时保留上次 pending 值，避免误报为 0。
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
            // 读本节点 push:stream:{server_id} 的消费者组 PEL 长度
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
     * 导出当前节点指标快照（HTTP sys_collector / 运维探针用）。
     *
     * 会先调用 refreshGauges 刷新 gauge；metrics.enable=false 时仅返回 metrics_enabled=0。
     *
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

    /** 单测重置：清零 _global 行全部字段 */
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

    /** metrics Table 是否已在当前进程创建（Server 启动前 record* 为 no-op） */
    private static function tableReady(): bool
    {
        return TableManager::isExistTable(self::TABLE);
    }
}
