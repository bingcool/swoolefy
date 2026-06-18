<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 节点与连接的全局标识。
 * conn_id = {server_id}:{fd}，fd 仅在单节点内有效，跨机推送必须依赖 conn_id 路由。
 */
class ClusterNodeIdentity
{
    public static function getServerId(): string
    {
        static $serverId = null;
        if ($serverId !== null) {
            return $serverId;
        }

        // 生产环境必须在 Config/websocket.php 显式配置唯一 server_id
        $configured = trim((string) (ClusterConfig::cluster()['server_id'] ?? ''));
        if ($configured !== '') {
            $serverId = $configured;
            return $serverId;
        }

        $host = php_uname('n') ?: 'localhost';
        $port = defined('WORKER_PORT') ? (int) WORKER_PORT : (int) (ClusterConfig::websocket()['port'] ?? 0);
        // 开发环境 fallback：hostname:port
        $serverId = $host . ':' . $port;

        return $serverId;
    }

    public static function makeConnId(int $fd): string
    {
        return self::getServerId() . ':' . $fd;
    }

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
