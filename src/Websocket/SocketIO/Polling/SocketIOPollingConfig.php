<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Core\Swfy;
use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * Socket.IO long-polling 跨 Worker / 跨节点共享存储配置。
 *
 * shared_store：
 * - memory：单 Worker 开发模式，会话与出站队列均在进程内存
 * - redis：Redis 出站队列 + Table sid 索引（及 cluster 开启时的 Redis 会话元数据）
 * - auto（默认）：cluster.enable 或 worker_num>1 时用 redis，否则 memory
 */
class SocketIOPollingConfig
{
    /** @var bool|null 单测覆盖 */
    private static ?bool $sharedStoreOverride = null;

    public static function setSharedStoreOverrideForTest(?bool $enabled): void
    {
        self::$sharedStoreOverride = $enabled;
    }

    public static function socketio(): array
    {
        $conf = ClusterConfig::websocket();
        $socketio = $conf['socketio'] ?? [];

        return is_array($socketio) ? $socketio : [];
    }

    public static function polling(): array
    {
        $polling = self::socketio()['polling'] ?? [];

        return is_array($polling) ? $polling : [];
    }

    public static function sharedStoreMode(): string
    {
        $mode = strtolower((string) (self::polling()['shared_store'] ?? 'auto'));

        return in_array($mode, ['memory', 'redis', 'auto'], true) ? $mode : 'auto';
    }

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

        return ClusterConfig::isEnabled() || self::workerNum() > 1;
    }

    public static function sessionTtl(): int
    {
        $ttl = (int) (self::polling()['session_ttl'] ?? 0);
        if ($ttl > 0) {
            return $ttl;
        }

        $clusterTtl = (int) (ClusterConfig::cluster()['conn_ttl'] ?? 180);

        return max(60, $clusterTtl);
    }

    public static function outboundMaxLen(): int
    {
        return max(16, (int) (self::polling()['outbound_max_len'] ?? 128));
    }

    /** 与 cluster.redis 相同结构；未配置时复用 cluster.redis */
    public static function redis(): array
    {
        $redis = self::polling()['redis'] ?? null;
        if (is_array($redis) && $redis !== []) {
            return $redis;
        }

        return ClusterConfig::redis();
    }

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
