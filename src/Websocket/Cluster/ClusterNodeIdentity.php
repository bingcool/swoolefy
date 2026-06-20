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
 * - **server_id**：节点唯一标识，跨机推送的路由键
 * - **fd**：Swoole 文件描述符，**仅在单节点进程内有效**，不可跨节点使用
 *
 * 集群推送流程中，远端节点收到 PushMessage 后按 targets 中的 fd 调用 server->push()。
 *
 * ## server_id 解析优先级
 *
 * 1. `Config/websocket.php` → `cluster.server_id`（生产环境必须显式配置）
 * 2. 开发 fallback：`{hostname}:{port}`
 *
 * @see RedisConnectionRegistry  conn_id 作为 Redis Hash key 的一部分
 * @see ClusterConfig::serverId()
 */
class ClusterNodeIdentity
{
    /**
     * 获取当前 WebSocket 节点的 server_id（进程内缓存）。
     *
     * 多 Worker 共享同一 server_id；不同物理机/容器必须配置不同值。
     */
    public static function getServerId(): string
    {
        static $serverId = null;
        if ($serverId !== null) {
            return $serverId;
        }

        $configured = trim((string) (ClusterConfig::cluster()['server_id'] ?? ''));
        if ($configured !== '') {
            $serverId = $configured;

            return $serverId;
        }

        $host = php_uname('n') ?: 'localhost';
        $port = defined('WORKER_PORT') ? (int) WORKER_PORT : (int) (ClusterConfig::websocket()['port'] ?? 0);
        $serverId = $host . ':' . $port;

        return $serverId;
    }

    /**
     * 根据本节点 fd 生成全局 conn_id。
     *
     * 在 onOpen 时若 Table 尚未写入 conn_id，由 Coordinator 调用。
     */
    public static function makeConnId(int $fd): string
    {
        return self::getServerId() . ':' . $fd;
    }

    /**
     * 从 conn_id 反解 server_id 与 fd。
     *
     * 使用 strrpos 取最后一个冒号，兼容 server_id 本身含冒号（如 host:port）。
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
}
