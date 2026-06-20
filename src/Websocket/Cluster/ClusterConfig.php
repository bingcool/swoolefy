<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Swfy;

/**
 * 读取 Config/websocket.php 中的 cluster 配置。
 *
 * 加载优先级（websocket()）：
 * 1. setWebsocketOverride() — 单测注入
 * 2. APP_PATH 已定义 — SystemEnv::loadWebsocketConf()，供 HTTP/CLI 外部推送
 * 3. Swfy::getConf() — WebSocket Worker 运行时
 */
class ClusterConfig
{
    /** @var array|null 测试或外部脚本可注入配置，绕过 Swfy */
    private static ?array $websocketOverride = null;

    public static function setWebsocketOverride(?array $conf): void
    {
        self::$websocketOverride = $conf;
    }

    public static function websocket(): array
    {
        if (self::$websocketOverride !== null) {
            return self::$websocketOverride;
        }

        // 外部进程（无 Swfy 容器）：直接读 APP_PATH/Config/websocket.php
        if (defined('APP_PATH')) {
            $conf = \Swoolefy\Core\SystemEnv::loadWebsocketConf();

            return is_array($conf) ? $conf : [];
        }

        try {
            $conf = Swfy::getConf();

            return is_array($conf['websocket'] ?? null) ? $conf['websocket'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function cluster(): array
    {
        $cluster = self::websocket()['cluster'] ?? [];

        return is_array($cluster) ? $cluster : [];
    }

    public static function isEnabled(): bool
    {
        return !empty(self::cluster()['enable']);
    }

    public static function serverId(): string
    {
        return ClusterNodeIdentity::getServerId();
    }

    public static function keyPrefix(): string
    {
        $prefix = (string) (self::cluster()['redis']['key_prefix'] ?? '');
        if ($prefix === '') {
            $prefix = 'ws:' . APP_NAME . ':';
        }

        return str_ends_with($prefix, ':') ? $prefix : $prefix . ':';
    }

    public static function pushChannelPrefix(): string
    {
        $prefix = (string) (self::cluster()['push']['channel_prefix'] ?? '');
        if ($prefix === '') {
            $prefix = 'ws:push:' . APP_NAME . ':';
        }

        return str_ends_with($prefix, ':') ? $prefix : $prefix . ':';
    }

    public static function pushChannelForServer(string $serverId): string
    {
        // 每节点独立频道，避免全集群广播造成无效流量
        return self::pushChannelPrefix() . $serverId;
    }

    /**
     * 本节点推送投递并行消费进程数。
     *
     * 1：订阅进程内同步投递（默认）
     * >1：订阅进程仅入队，另起 N 个 WebsocketPushDeliveryProcess BRPOP 并行投递
     */
    public static function pushDeliveryProcessNum(): int
    {
        $push = self::cluster()['push'] ?? [];
        if (!is_array($push)) {
            return 1;
        }

        return max(1, (int) ($push['delivery_process_num'] ?? 1));
    }

    /** 本节点推送本地队列 Redis List key（仅 transport=pubsub 且 delivery_process_num>1） */
    public static function pushDeliveryQueueKey(): string
    {
        $push = self::cluster()['push'] ?? [];
        $custom = is_array($push) ? trim((string) ($push['delivery_queue_key'] ?? '')) : '';
        if ($custom !== '') {
            return $custom;
        }

        return self::keyPrefix() . 'push:queue:' . self::serverId();
    }

    /**
     * 推送总线传输：streams（默认，持久化 + 消费组）| pubsub（兼容，不持久化）
     *
     * streams 解决消费进程偶发崩溃导致的消息丢失；不解决用户离线必达（需业务 DB）。
     */
    public static function pushTransport(): string
    {
        $push = self::cluster()['push'] ?? [];
        if (!is_array($push)) {
            return 'streams';
        }

        $transport = strtolower((string) ($push['transport'] ?? 'streams'));

        return in_array($transport, ['streams', 'pubsub'], true) ? $transport : 'streams';
    }

    public static function usesPushStreams(): bool
    {
        return self::pushTransport() === 'streams';
    }

    /**
     * 每节点独立 Stream：{key_prefix}push:stream:{server_id}
     *
     * 按节点分 Stream，避免全集群共用一个 Stream 导致无关节点竞争消费。
     */
    public static function pushStreamKeyForServer(string $serverId): string
    {
        $push = self::cluster()['push'] ?? [];
        $prefix = is_array($push) ? trim((string) ($push['stream_key_prefix'] ?? '')) : '';
        if ($prefix === '') {
            $prefix = self::keyPrefix() . 'push:stream:';
        }
        if (!str_ends_with($prefix, ':')) {
            $prefix .= ':';
        }

        return $prefix . $serverId;
    }

    /**
     * 消费组名（每个 Stream 内唯一组即可，默认可共用 deliver）。
     *
     * 组内 delivery_process_num 个 consumer 竞争 XREADGROUP，每条消息只投递一次。
     */
    public static function pushStreamGroup(): string
    {
        $push = self::cluster()['push'] ?? [];

        return (string) (is_array($push) ? ($push['stream_group'] ?? 'deliver') : 'deliver');
    }

    /** 组内 consumer 唯一标识，含进程 index 与 pid */
    public static function pushStreamConsumerName(int $index): string
    {
        return sprintf('push-%d-%d', $index, getmypid());
    }

    /**
     * XADD 后 MAXLEN ~ 裁剪上限。
     *
     * 消费慢于生产时防止 Stream 撑爆内存；过旧且已 ACK 的条目会被 Redis 淘汰。
     */
    public static function pushStreamMaxLen(): int
    {
        $push = self::cluster()['push'] ?? [];

        return max(1000, (int) (is_array($push) ? ($push['stream_max_len'] ?? 50000) : 50000));
    }

    /**
     * XAUTOCLAIM 最小空闲毫秒。
     *
     * PEL 中消息空闲超过该值视为原 consumer 崩溃，转给当前 consumer 重试。
     * 应大于单次 push 批处理的最坏耗时，建议 30s 起。
     */
    public static function pushStreamClaimIdleMs(): int
    {
        $push = self::cluster()['push'] ?? [];

        return max(1000, (int) (is_array($push) ? ($push['stream_claim_idle_ms'] ?? 30000) : 30000));
    }

    /** XREADGROUP BLOCK 毫秒 */
    public static function pushStreamBlockMs(): int
    {
        $push = self::cluster()['push'] ?? [];

        return max(100, (int) (is_array($push) ? ($push['stream_block_ms'] ?? 5000) : 5000));
    }

    /** 每次 XREADGROUP / XAUTOCLAIM 拉取条数 */
    public static function pushStreamReadCount(): int
    {
        $push = self::cluster()['push'] ?? [];

        return max(1, min(100, (int) (is_array($push) ? ($push['stream_read_count'] ?? 10) : 10)));
    }

    public static function connTtl(): int
    {
        return max(30, (int) (self::cluster()['conn_ttl'] ?? 180));
    }

    public static function cleanupInterval(): int
    {
        return max(10, (int) (self::cluster()['cleanup_interval'] ?? 30));
    }

    /**
     * Redis touch 写间隔（秒）。
     *
     * 本地 Table 仍每条消息刷新 last_active_at；仅同步 Redis 全局索引时节流。
     * 默认与 heartbeat_check_interval 对齐，应小于 conn_ttl。
     */
    public static function touchInterval(): int
    {
        $cluster = self::cluster();
        if (array_key_exists('touch_interval', $cluster)) {
            return max(5, (int) $cluster['touch_interval']);
        }

        $websocket = self::websocket();

        return max(5, (int) ($websocket['heartbeat_check_interval'] ?? 30));
    }

    public static function onRedisFailure(): string
    {
        $policy = (string) (self::cluster()['on_redis_failure'] ?? 'reject_open');

        return in_array($policy, ['reject_open', 'local_only'], true) ? $policy : 'reject_open';
    }

    public static function redis(): array
    {
        $redis = self::cluster()['redis'] ?? [];
        if (!is_array($redis)) {
            $redis = [];
        }

        if (!empty($redis['host'])) {
            return $redis;
        }

        $dc = \Swoolefy\Core\SystemEnv::loadDcEnv();
        $fallback = is_array($dc['websocket_cluster_redis'] ?? null) ? $dc['websocket_cluster_redis'] : [];

        return array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 2.0,
        ], $fallback, $redis);
    }

    /**
     * 推送载荷扩展配置（Config/websocket.php 顶层 push 段）。
     *
     * 与 cluster.push（Redis Pub/Sub 频道前缀）无关，用于 push.enricher 配置。
     */
    public static function pushSettings(): array
    {
        $push = self::websocket()['push'] ?? [];

        return is_array($push) ? $push : [];
    }
}
