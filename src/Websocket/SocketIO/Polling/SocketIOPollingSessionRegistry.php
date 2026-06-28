<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;

/**
 * Engine.IO polling 会话注册表：sid → virtual_fd（跨 Worker 可解析）。
 *
 * ## 问题
 *
 * polling 连接没有真实 WebSocket fd，框架用 **虚拟 fd**（≥ 0x40000000）写入
 * {@see WebsocketConnectionManager} 的 Table，以便 push / group / cluster 与 WS 连接统一。
 * 客户端每次 poll 携带 `sid`；任意 Worker 必须能根据 sid 找到 virtual_fd，否则返回
 * `Unknown session id`。
 *
 * ## 双层存储
 *
 * | 层级 | 介质 | 范围 | 内容 |
 * |------|------|------|------|
 * | 同节点 | Swoole Table `table_websocket_polling_sid` | 本机所有 Worker | virtual_fd, last_active_at |
 * | 跨节点 | Redis Hash `{prefix}poll:sid:{sid}` | 集群（shared_store 时） | sid, virtual_fd, server_id, conn_id, 时间戳 |
 *
 * - **exists / getVirtualFd**：先查 Table（快），miss 再查 Redis（跨节点或 Table 未就绪）
 * - **register / touch / remove**：双写 Table + Redis（Redis 仅在 usesSharedStore() 时）
 *
 * ## virtual_fd 分配
 *
 * 共享模式下由 {@see SocketIOSessionManager::allocateVirtualFd()} 通过
 * `table_websocket_polling_meta.seq` 全局递增，保证同节点 Worker 间不冲突。
 *
 * ## Table 定义
 *
 * {@see tableDefinitions()} 在 WebsocketConnectionManager 启动时合并进全局 Table 配置；
 * 仅当 socketio.allow_polling=true 时注册。
 *
 * ## Redis Hash 字段
 *
 * - sid, virtual_fd, server_id, conn_id, created_at, last_active_at
 * - EXPIRE = polling.session_ttl；touch 时刷新
 *
 * @see SocketIOPollingConfig
 * @see SocketIOPollingOutboundStore  会话删除时联动清除 poll:out 队列
 * @see SocketIOSessionManager          对外门面
 */
class SocketIOPollingSessionRegistry
{
    /** sid 索引表名，key = Engine.IO sid 字符串 */
    public const TABLE_POLLING_SID = 'table_websocket_polling_sid';

    /**
     * 握手成功后注册 sid 与虚拟 fd 的映射。
     *
     * @param string $sid       Engine.IO 生成的 session id
     * @param int    $virtualFd  已分配的虚拟 fd，须 > 0
     * @param string $connId    集群 conn_id（server_id:virtual_fd），可选
     */
    public static function register(string $sid, int $virtualFd, string $connId = ''): void
    {
        if ($sid === '' || $virtualFd <= 0) {
            return;
        }

        $now = time();
        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            TableManager::set(self::TABLE_POLLING_SID, $sid, [
                'virtual_fd' => $virtualFd,
                'last_active_at' => $now,
            ]);
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $virtualFd, $connId, $now): void {
            $key = self::redisSessionKey($sid);
            $redis->hMSet($key, [
                'sid' => $sid,
                'virtual_fd' => (string) $virtualFd,
                'server_id' => ClusterNodeIdentity::getServerId(),
                'conn_id' => $connId,
                'created_at' => (string) $now,
                'last_active_at' => (string) $now,
            ]);
            $redis->expire($key, SocketIOPollingConfig::sessionTtl());
        });
    }

    /**
     * 判断 sid 是否仍有效（poll GET/POST 前置校验）。
     *
     * 先查本节点 Table；共享模式下 Table miss 再查 Redis EXISTS。
     */
    public static function exists(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID) && TableManager::exist(self::TABLE_POLLING_SID, $sid)) {
            return true;
        }

        if (!self::shouldMirrorRedis()) {
            return false;
        }

        try {
            return (bool) self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid) {
                return $redis->exists(self::redisSessionKey($sid));
            });
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * 根据 sid 解析虚拟 fd，供 ConnectionManager / push 路由使用。
     *
     * @return int virtual_fd，未找到返回 0
     */
    public static function getVirtualFd(string $sid): int
    {
        if ($sid === '') {
            return 0;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            $row = TableManager::get(self::TABLE_POLLING_SID, $sid);
            if (is_array($row) && !empty($row)) {
                return (int) ($row['virtual_fd'] ?? 0);
            }
        }

        if (!self::shouldMirrorRedis()) {
            return 0;
        }

        try {
            $meta = self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid) {
                return $redis->hGetAll(self::redisSessionKey($sid));
            });
            if (is_array($meta) && !empty($meta['virtual_fd'])) {
                return (int) $meta['virtual_fd'];
            }
        } catch (\Throwable $throwable) {
            return 0;
        }

        return 0;
    }

    /**
     * 刷新会话活跃时间（poll 请求、业务 touch 时调用）。
     *
     * 更新 Table last_active_at，并刷新 Redis Hash 的 last_active_at + EXPIRE。
     * Redis 失败不影响 HTTP 主流程。
     */
    public static function touch(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        $now = time();
        if (TableManager::isExistTable(self::TABLE_POLLING_SID) && TableManager::exist(self::TABLE_POLLING_SID, $sid)) {
            $row = TableManager::get(self::TABLE_POLLING_SID, $sid);
            if (is_array($row)) {
                $row['last_active_at'] = $now;
                TableManager::set(self::TABLE_POLLING_SID, $sid, $row);
            }
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid, $now): void {
                $key = self::redisSessionKey($sid);
                $redis->hSet($key, 'last_active_at', (string) $now);
                $redis->expire($key, SocketIOPollingConfig::sessionTtl());
            });
        } catch (\Throwable $throwable) {
            // touch 失败不影响主流程
        }
    }

    /**
     * 会话关闭或 upgrade 完成后移除 sid 索引。
     *
     * 同时删除 Redis 会话 Hash 与 {@see SocketIOPollingOutboundStore} 出站 List。
     */
    public static function remove(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        if (TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            TableManager::del(self::TABLE_POLLING_SID, $sid);
        }

        if (!self::shouldMirrorRedis()) {
            return;
        }

        try {
            self::executeRedis(static function (ClusterRedisAdapterInterface $redis) use ($sid): void {
                $redis->del(self::redisSessionKey($sid));
                $redis->del(SocketIOPollingOutboundStore::redisQueueKey($sid));
            });
        } catch (\Throwable $throwable) {
            // 清理失败可依赖 TTL 兜底
        }
    }

    /**
     * 供 WebsocketConnectionManager 合并的 Swoole Table 结构定义。
     *
     * - TABLE_POLLING_SID：sid → virtual_fd / last_active_at
     * - TABLE_POLLING_META：全局 virtual_fd 序号（seq 字段，incr 分配）
     *
     * @param int $size sid 表行数上限，默认 65536
     */
    public static function tableDefinitions(int $size = 65536): array
    {
        return [
            self::TABLE_POLLING_SID => [
                'size' => $size,
                'fields' => [
                    ['virtual_fd', 'int', 8],
                    ['last_active_at', 'int', 8],
                ],
            ],
            SocketIOSessionManager::TABLE_POLLING_META => [
                'size' => 1,
                'fields' => [
                    ['seq', 'int', 8],
                ],
            ],
        ];
    }

    /** 单测 teardown：清空 sid Table 全部行 */
    public static function resetForTest(): void
    {
        if (!TableManager::isExistTable(self::TABLE_POLLING_SID)) {
            return;
        }

        foreach (TableManager::getTableKeys(self::TABLE_POLLING_SID) as $sid) {
            TableManager::del(self::TABLE_POLLING_SID, (string) $sid);
        }
    }

    /** 与 SocketIOPollingConfig::usesSharedStore() 一致，决定是否写 Redis */
    private static function shouldMirrorRedis(): bool
    {
        return SocketIOPollingConfig::usesSharedStore();
    }

    /** Redis Hash 键：{prefix}poll:sid:{sid} */
    private static function redisSessionKey(string $sid): string
    {
        return SocketIOPollingConfig::redisKeyPrefix() . 'poll:sid:' . $sid;
    }

    private static function executeRedis(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }
}
