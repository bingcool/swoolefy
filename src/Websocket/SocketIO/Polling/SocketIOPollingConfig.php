<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Core\Swfy;
use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * Socket.IO long-polling 共享存储配置解析器。
 *
 * ## 背景
 *
 * Engine.IO long-polling 使用 HTTP GET/POST 轮询，同一 sid 的握手、poll、push 可能落在
 * Swoole 的不同 Worker 进程。若 sid 与出站队列仅存于 Worker 本地内存，会出现
 * `Unknown session id` 或 push 丢失。本类决定何时启用「跨 Worker 共享存储」。
 *
 * ## 配置项（Config/socketio.php → polling）
 *
 * ```php
 * 'polling' => [
 *     'shared_store' => 'auto',   // auto | memory | redis
 *     'session_ttl' => 180,       // sid / 出站队列 Redis 过期秒数
 *     'outbound_max_len' => 128,    // 每 sid 出站 List 最大条数（LTRIM 保留尾部）
     *     'short_poll_wait_sec' => 2,   // 单 sid 唯一 waiter 的 BRPOP 秒数（1~5，兼顾 QPS 与 push 延迟）
     *     'session_touch_interval' => 15, // Redis 会话 touch 节流秒数（Table 仍每次更新）
     *     'redis' => [...],           // 可选，未配置时复用 cluster.redis
 * ],
 * ```
 *
 * ## shared_store 模式
 *
 * | 模式   | usesSharedStore() | 说明 |
 * |--------|-------------------|------|
 * | memory | false             | sid 与出站包均在进程内存，仅适合 worker_num=1 开发 |
 * | redis  | true              | 强制 Table sid + Redis 出站/会话元数据 |
 * | auto   | 见下              | 默认；生产推荐 |
 *
 * auto 判定：`cluster.enable === true` **或** `setting.worker_num > 1` 时启用共享存储。
 *
 * ## 与其它组件的关系
 *
 * - {@see SocketIOPollingSessionRegistry}：共享模式下写 Table + Redis Hash
 * - {@see SocketIOPollingOutboundStore}：共享模式下用 Redis List 存 Engine.IO 出站包
 * - {@see SocketIOSessionManager}：门面，根据 usesSharedStore() 分支 memory / 共享
 */
class SocketIOPollingConfig
{
    /** @var bool|null 单测注入：覆盖 usesSharedStore() 返回值，null 表示读配置 */
    private static ?bool $sharedStoreOverride = null;

    /**
     * 单测专用：强制开启/关闭共享存储，传 null 恢复读配置。
     */
    public static function setSharedStoreOverrideForTest(?bool $enabled): void
    {
        self::$sharedStoreOverride = $enabled;
    }

    /** 读取 websocket 配置中的 socketio 段 */
    public static function socketio(): array
    {
        $conf = ClusterConfig::websocket();
        $socketio = $conf['socketio'] ?? [];

        return is_array($socketio) ? $socketio : [];
    }

    /** 读取 socketio.polling 段，缺省为 [] */
    public static function polling(): array
    {
        $polling = self::socketio()['polling'] ?? [];

        return is_array($polling) ? $polling : [];
    }

    /**
     * 原始 shared_store 字符串，非法值回退 auto。
     *
     * @return 'memory'|'redis'|'auto'
     */
    public static function sharedStoreMode(): string
    {
        $mode = strtolower((string) (self::polling()['shared_store'] ?? 'auto'));

        return in_array($mode, ['memory', 'redis', 'auto'], true) ? $mode : 'auto';
    }

    /**
     * 是否启用跨 Worker 共享存储（Table sid + Redis 出站/会话）。
     *
     * 业务代码与 SessionRegistry / OutboundStore 均以此为准分支。
     */
    public static function usesSharedStore(): bool
    {
        if (self::$sharedStoreOverride !== null) {
            return self::$sharedStoreOverride;
        }

        $mode = self::sharedStoreMode();
        if ($mode === 'memory') {
            return false;
        }
        if ($mode === 'redis') {
            return true;
        }

        // auto：集群或多 Worker 即启用
        return ClusterConfig::isEnabled() || self::workerNum() > 1;
    }

    /**
     * polling 会话与 Redis 键 TTL（秒）。
     *
     * 优先 polling.session_ttl；未配置则沿用 cluster.conn_ttl，最小 60。
     */
    public static function sessionTtl(): int
    {
        $ttl = (int) (self::polling()['session_ttl'] ?? 0);
        if ($ttl > 0) {
            return $ttl;
        }

        $clusterTtl = (int) (ClusterConfig::cluster()['conn_ttl'] ?? 180);

        return max(60, $clusterTtl);
    }

    /**
     * 每 sid 出站 Redis List 最大长度；超出时 LTRIM 保留最新 N 条，防止内存膨胀。
     */
    public static function outboundMaxLen(): int
    {
        return max(16, (int) (self::polling()['outbound_max_len'] ?? 128));
    }

    /**
     * long-poll 短阻塞秒数：单 sid 唯一 waiter 上 BRPOP 的时长。
     *
     * 过小 push 延迟高；过大占用 Worker 且拖慢 POST connect。默认 2，上限 5。
     *
     * @param int $pollTimeout socketio.poll_timeout，作参考上限
     */
    public static function shortPollWaitSec(int $pollTimeout = 25): int
    {
        $configured = (int) (self::polling()['short_poll_wait_sec'] ?? 2);
        $waitSec = $configured > 0 ? $configured : 2;

        return max(1, min($waitSec, 5, max(1, $pollTimeout)));
    }

    /**
     * polling 会话 Redis touch 节流间隔（秒）。
     *
     * 本地 Table 每次 poll 仍更新；仅距上次 Redis touch 超过此间隔才 hSet+EXPIRE。
     * 未配置时沿用 cluster.touch_interval，最小 5。
     */
    public static function sessionTouchInterval(): int
    {
        $polling = self::polling();
        if (array_key_exists('session_touch_interval', $polling)) {
            return max(5, (int) $polling['session_touch_interval']);
        }

        return ClusterConfig::touchInterval();
    }

    /**
     * polling 专用 Redis 连接参数。
     *
     * 结构与 cluster.redis 相同（host/port/database/client 等）；
     * polling.redis 未配置或为空时复用 cluster.redis。
     */
    public static function redis(): array
    {
        $redis = self::polling()['redis'] ?? null;
        if (is_array($redis) && $redis !== []) {
            return $redis;
        }

        return ClusterConfig::redis();
    }

    /**
     * Redis 键前缀，用于 poll:sid:* / poll:out:* 等键。
     *
     * - 集群开启：与 cluster 一致（ClusterConfig::keyPrefix()）
     * - 非集群：polling.redis.key_prefix，或 ws:{APP_NAME}:
     */
    public static function redisKeyPrefix(): string
    {
        if (ClusterConfig::isEnabled()) {
            return ClusterConfig::keyPrefix();
        }

        $prefix = (string) (self::polling()['redis']['key_prefix'] ?? '');
        if ($prefix === '') {
            $app = defined('APP_NAME') ? APP_NAME : 'websocket';

            return 'ws:' . $app . ':';
        }

        return str_ends_with($prefix, ':') ? $prefix : $prefix . ':';
    }

    /** 从 Swoole 全局 setting 读取 worker_num，CLI/单测不可用时视为 1 */
    private static function workerNum(): int
    {
        try {
            $setting = Swfy::getConf()['setting'] ?? [];

            return max(1, (int) ($setting['worker_num'] ?? 1));
        } catch (\Throwable $throwable) {
            return 1;
        }
    }
}
