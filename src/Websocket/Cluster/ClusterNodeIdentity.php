<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * WebSocket 集群节点与连接的全局标识。
 *
 * ## conn_id 格式
 *
 * ```
 * conn_id = {server_id}:{fd}
 * ```
 *
 * - **server_id**：节点唯一标识，跨机推送路由键、Redis Stream/频道后缀
 * - **fd**：Swoole 文件描述符，**仅在单节点进程内有效**
 *
 * ## server_id 解析顺序（cluster.enable=true 时前两项必填其一）
 *
 * 1. `Config/websocket.php` → `cluster.server_id`
 * 2. 环境变量 `WS_SERVER_ID`（推荐生产使用）
 * 3. 环境变量 `SERVER_ID`
 * 4. 单机模式（cluster.enable=false）fallback：`{hostname}:{port}`
 *
 * ## 为何禁止集群模式下随机 / hostname fallback
 *
 * - `conn_id`、Redis `node:{server_id}`、推送 Stream `push:stream:{server_id}` 均依赖稳定 server_id
 * - 使用 `rand()` 或重启后变化的 hostname:port 会导致：
 *   - 僵尸索引无法清理
 *   - 跨节点推送路由到错误 Stream
 *   - broadcast 向已下线节点 ID 发消息
 *
 * @see RedisConnectionRegistry
 * @see ClusterConfig::serverId()
 */
class ClusterNodeIdentity
{
    private static ?string $cachedServerId = null;
    private static bool $serverIdResolved = false;

    /**
     * 获取当前 WebSocket 节点的 server_id（进程内单例缓存）。
     *
     * @throws ClusterRedisException cluster.enable=true 且未配置 server_id 时
     */
    public static function getServerId(): string
    {
        if (self::$serverIdResolved && self::$cachedServerId !== null) {
            return self::$cachedServerId;
        }

        $configured = self::resolveConfiguredServerId();
        if ($configured !== '') {
            self::$cachedServerId = $configured;
            self::$serverIdResolved = true;

            return self::$cachedServerId;
        }

        if (ClusterConfig::isEnabled()) {
            throw new ClusterRedisException(
                'cluster.server_id must be configured when cluster.enable=true (set cluster.server_id or WS_SERVER_ID)'
            );
        }

        $host = php_uname('n') ?: 'localhost';
        $port = defined('WORKER_PORT') ? (int) WORKER_PORT : (int) (ClusterConfig::websocket()['port'] ?? 0);
        self::$cachedServerId = $host . ':' . $port;
        self::$serverIdResolved = true;

        return self::$cachedServerId;
    }

    /** 根据本节点 fd 生成全局 conn_id（onOpen 时写入 Table 与 Redis） */
    public static function makeConnId(int $fd): string
    {
        return self::getServerId() . ':' . $fd;
    }

    /**
     * 从 conn_id 反解 server_id 与 fd。
     *
     * 使用 strrpos 取最后一个 `:`，兼容 server_id 本身含冒号（如 `10.0.0.1:9508`）。
     *
     * @return array{server_id: string, fd: int}
     */
    public static function parseConnId(string $connId): array
    {
        $pos = strrpos($connId, ':');
        if ($pos === false) {
            return ['server_id' => '', 'fd' => 0];
        }

        return [
            'server_id' => substr($connId, 0, $pos),
            'fd' => (int) substr($connId, $pos + 1),
        ];
    }

    /** 单测或 ClusterConfig::setWebsocketOverride() 后重置缓存 */
    public static function reset(): void
    {
        self::$cachedServerId = null;
        self::$serverIdResolved = false;
    }

    /**
     * 从配置文件与环境变量读取显式 server_id（不含 hostname fallback）。
     */
    private static function resolveConfiguredServerId(): string
    {
        $configured = trim((string) (ClusterConfig::cluster()['server_id'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        if (function_exists('env')) {
            $fromEnv = trim((string) env('WS_SERVER_ID', ''));
            if ($fromEnv !== '') {
                return $fromEnv;
            }

            $fromEnv = trim((string) env('SERVER_ID', ''));
            if ($fromEnv !== '') {
                return $fromEnv;
            }
        }

        return '';
    }
}
