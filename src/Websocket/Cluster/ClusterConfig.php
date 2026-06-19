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

    public static function connTtl(): int
    {
        return max(30, (int) (self::cluster()['conn_ttl'] ?? 180));
    }

    public static function cleanupInterval(): int
    {
        return max(10, (int) (self::cluster()['cleanup_interval'] ?? 30));
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
}
