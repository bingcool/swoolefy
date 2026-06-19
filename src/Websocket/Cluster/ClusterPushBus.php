<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;

/**
 * 集群推送总线（Worker / 外部进程共用扇出逻辑）。
 *
 * 流程：
 * 1. 查 Redis 全局索引（group / user / conn meta）
 * 2. 按 server_id 分组 targets
 * 3. 本节点（$localServer 非空且 server_id 匹配）→ PushDeliveryHandler 直推
 * 4. 其余节点 → PUBLISH 到 ws:push:{app}:{server_id}，由 WebsocketPushSubscriberProcess 投递
 *
 * $localServer=null 表示外部进程：全部走 Redis，不调用 server->push()。
 */
class ClusterPushBus
{
    public static function publishToGroup(string $group, string $event, $data = [], ?Server $localServer = null): int
    {
        self::assertClusterEnabled();

        // group:{group} Set → conn_id 列表 → pipeline 批量查 meta
        return self::fanoutByConnIds(
            RedisConnectionRegistry::getConnIdsByGroup($group),
            $event,
            $data,
            $localServer,
            self::resolveSource($localServer)
        );
    }

    public static function publishToUser(string $userId, string $event, $data = [], ?Server $localServer = null): int
    {
        self::assertClusterEnabled();

        // user:{user_id} Set → conn_id 列表（支持多端同时在线）
        return self::fanoutByConnIds(
            RedisConnectionRegistry::getConnIdsByUser($userId),
            $event,
            $data,
            $localServer,
            self::resolveSource($localServer)
        );
    }

    public static function publishBroadcast(string $event, $data = [], ?Server $localServer = null): int
    {
        self::assertClusterEnabled();

        $source = self::resolveSource($localServer);
        $message = PushMessage::broadcast($event, $data, $source);
        $localServerId = $localServer ? ClusterNodeIdentity::getServerId() : '';
        $count = 0;
        $remotePublishes = [];

        // nodes Set 列出所有在线节点，每节点一条 broadcast 指令（非全频道广播）
        foreach (RedisConnectionRegistry::getAllNodeIds() as $serverId) {
            if ($localServer !== null && $serverId === $localServerId) {
                // Worker 内：本节点遍历本地 Table 投递
                $count += PushDeliveryHandler::deliver($localServer, $message);
                continue;
            }
            $remotePublishes[] = [$serverId, $message];
            $count++;
        }

        RedisConnectionRegistry::publishMany($remotePublishes);

        return $count;
    }

    /**
     * 按已知 targets 扇出（ClusterPushDispatcher::pushEventToFd 使用）。
     *
     * @param array<int, array{fd:int,conn_id:string,server_id?:string}> $targets
     */
    public static function publishToTargets(array $targets, string $event, $data = [], ?Server $localServer = null): int
    {
        self::assertClusterEnabled();

        return self::fanout(
            $targets,
            $event,
            $data,
            $localServer,
            self::resolveSource($localServer)
        );
    }

    /** 外部推送必须开启集群，否则无 Redis 索引与 Pub/Sub 频道 */
    public static function assertClusterEnabled(): void
    {
        if (!ClusterConfig::isEnabled()) {
            throw new ClusterRedisException(
                'WebSocket cluster push requires cluster.enable=true in Config/websocket.php'
            );
        }
    }

    private static function fanoutByConnIds(
        array $connIds,
        string $event,
        $data,
        ?Server $localServer,
        string $source
    ): int {
        $targets = self::targetsFromConnIds($connIds);

        return self::fanout($targets, $event, $data, $localServer, $source);
    }

    /**
     * @param array<int, array{fd:int,conn_id:string,server_id:string}> $targets
     */
    private static function fanout(
        array $targets,
        string $event,
        $data,
        ?Server $localServer,
        string $source
    ): int {
        if (empty($targets)) {
            return 0;
        }

        $localServerId = $localServer ? ClusterNodeIdentity::getServerId() : '';
        // 同一 server_id 合并为一条 Pub/Sub 消息，减少 Redis 往返
        $grouped = [];
        foreach ($targets as $target) {
            $serverId = (string) ($target['server_id'] ?? '');
            if ($serverId === '') {
                // meta 缺失时从 conn_id（{server_id}:{fd}）反解
                $parsed = ClusterNodeIdentity::parseConnId((string) ($target['conn_id'] ?? ''));
                $serverId = $parsed['server_id'];
                $target['server_id'] = $serverId;
                if ((int) ($target['fd'] ?? 0) <= 0) {
                    $target['fd'] = $parsed['fd'];
                }
            }
            $grouped[$serverId][] = [
                'fd' => (int) ($target['fd'] ?? 0),
                'conn_id' => (string) ($target['conn_id'] ?? ''),
            ];
        }

        $count = 0;
        $remotePublishes = [];
        foreach ($grouped as $serverId => $serverTargets) {
            // 只传 event+data，Socket.IO 编码在投递端按 is_socketio 决定
            $message = PushMessage::event($serverTargets, $event, $data, $source);
            if ($localServer !== null && $serverId === $localServerId) {
                $count += PushDeliveryHandler::deliver($localServer, $message);
                continue;
            }
            $remotePublishes[] = [$serverId, $message];
            $count += count($serverTargets);
        }

        RedisConnectionRegistry::publishMany($remotePublishes);

        return $count;
    }

    /** conn_id → pipeline 批量读 Redis conn Hash，过滤已过期连接 */
    private static function targetsFromConnIds(array $connIds): array
    {
        $connIds = array_values(array_unique(array_filter($connIds, static function ($connId) {
            return is_string($connId) && $connId !== '';
        })));
        if ($connIds === []) {
            return [];
        }

        $metaMap = RedisConnectionRegistry::getConnectionMetaMany($connIds);
        $targets = [];
        foreach ($connIds as $connId) {
            $meta = $metaMap[$connId] ?? null;
            if ($meta === null) {
                continue;
            }
            $targets[] = [
                'fd' => (int) ($meta['fd'] ?? 0),
                'conn_id' => $connId,
                'server_id' => (string) ($meta['server_id'] ?? ''),
            ];
        }

        return $targets;
    }

    /** 写入 PushMessage.source，便于日志区分 Worker 推送与 HTTP/CLI 推送 */
    private static function resolveSource(?Server $localServer): string
    {
        return $localServer === null ? 'external' : ClusterNodeIdentity::getServerId();
    }
}
