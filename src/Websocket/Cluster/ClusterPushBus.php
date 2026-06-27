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
 * 4. 其余节点 → XADD Stream（默认）或 PUBLISH（pubsub），由推送消费进程投递
 *
 * $localServer=null 表示外部进程：全部走 Redis，不调用 server->push()。
 */
class ClusterPushBus
{
    /**
     * 向小组下所有连接扇出推送（跨节点）。
     *
     * @return PushFanoutResult 扇出结果（离线落库见 OfflineMessageCoordinator）
     */
    public static function publishToGroup(string $group, string $event, $data = [], ?Server $localServer = null): PushFanoutResult
    {
        self::assertClusterEnabled();

        $result = self::fanoutByConnIds(
            RedisConnectionRegistry::getConnIdsByGroup($group),
            $event,
            $data,
            $localServer,
            self::resolveSource($localServer),
            null,
            'group',
            $group
        );
        $result->fanoutScope = 'group';
        $result->fanoutGroup = $group;

        return $result;
    }

    /**
     * 向用户下所有连接扇出推送（支持同一用户多端同时在线）。
     *
     * @return PushFanoutResult 扇出结果（离线落库请用 shouldStoreOfflineAtPush()）
     */
    public static function publishToUser(string $userId, string $event, $data = [], ?Server $localServer = null): PushFanoutResult
    {
        self::assertClusterEnabled();
        self::assertNonEmptyUserId($userId);

        $result = self::fanoutByConnIds(
            RedisConnectionRegistry::getConnIdsByUser($userId),
            $event,
            $data,
            $localServer,
            self::resolveSource($localServer),
            $userId,
            'user'
        );
        $result->fanoutScope = 'user';

        return $result;
    }

    /**
     * 全集群广播：向 nodes 集合中每个在线节点发一条 broadcast 指令。
     *
     * @return PushFanoutResult targetCount 为在线节点数；无节点时可用 data.offline_user_ids 落库
     */
    public static function publishBroadcast(string $event, $data = [], ?Server $localServer = null): PushFanoutResult
    {
        self::assertClusterEnabled();

        $source = self::resolveSource($localServer);
        $message = PushMessage::broadcast($event, $data, $source);
        $localServerId = $localServer ? ClusterNodeIdentity::getServerId() : '';
        $result = new PushFanoutResult();
        $result->fanoutScope = 'broadcast';
        $remotePublishes = [];
        $nodeIds = RedisConnectionRegistry::getAllNodeIds();
        $result->targetCount = count($nodeIds);

        foreach ($nodeIds as $serverId) {
            if ($localServer !== null && $serverId === $localServerId) {
                $result->delivered += PushDeliveryHandler::deliver($localServer, $message)->deliveredCount();
                continue;
            }
            $remotePublishes[] = [$serverId, $message];
            $result->remoteTargetCount++;
        }

        RedisConnectionRegistry::publishMany($remotePublishes);

        return $result;
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
        )->reportedHitCount();
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
        string $source,
        ?string $recipientUserId = null,
        string $fanoutScope = 'targets',
        ?string $fanoutGroup = null
    ): PushFanoutResult {
        $targets = self::targetsFromConnIds($connIds);

        return self::fanout(
            $targets,
            $event,
            $data,
            $localServer,
            $source,
            $recipientUserId,
            $fanoutScope,
            $fanoutGroup
        );
    }

    /**
     * @param array<int, array{fd:int,conn_id:string,server_id:string,user_id?:string}> $targets
     */
    private static function fanout(
        array $targets,
        string $event,
        $data,
        ?Server $localServer,
        string $source,
        ?string $recipientUserId = null,
        string $fanoutScope = 'targets',
        ?string $fanoutGroup = null
    ): PushFanoutResult {
        $result = new PushFanoutResult();
        $result->targetCount = count($targets);
        $result->fanoutScope = $fanoutScope;
        $result->fanoutGroup = $fanoutGroup !== null && $fanoutGroup !== '' ? $fanoutGroup : null;
        $result->targetUserIds = self::collectTargetUserIds($targets, $recipientUserId);
        if ($targets === []) {
            return $result;
        }

        $localServerId = $localServer ? ClusterNodeIdentity::getServerId() : '';
        $grouped = [];
        foreach ($targets as $target) {
            $serverId = (string) ($target['server_id'] ?? '');
            if ($serverId === '') {
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

        $remotePublishes = [];
        foreach ($grouped as $serverId => $serverTargets) {
            $message = PushMessage::event(
                $serverTargets,
                $event,
                $data,
                $source,
                '',
                '',
                $recipientUserId,
                $fanoutGroup,
                $fanoutScope
            );
            if ($localServer !== null && $serverId === $localServerId) {
                $result->delivered += PushDeliveryHandler::deliver($localServer, $message)->deliveredCount();
                continue;
            }
            $remotePublishes[] = [$serverId, $message];
            $result->remoteTargetCount += count($serverTargets);
        }

        RedisConnectionRegistry::publishMany($remotePublishes);

        return $result;
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
                'user_id' => (string) ($meta['user_id'] ?? ''),
            ];
        }

        return $targets;
    }

    /**
     * @param array<int, array{fd:int,conn_id:string,server_id?:string,user_id?:string}> $targets
     *
     * @return string[]
     */
    private static function collectTargetUserIds(array $targets, ?string $recipientUserId): array
    {
        $userIds = [];
        if ($recipientUserId !== null && trim($recipientUserId) !== '') {
            $userIds[] = trim($recipientUserId);
        }

        foreach ($targets as $target) {
            $userId = trim((string) ($target['user_id'] ?? ''));
            if ($userId !== '') {
                $userIds[] = $userId;
            }
        }

        return array_values(array_unique($userIds));
    }

    private static function resolveSource(?Server $localServer): string
    {
        return $localServer === null ? 'external' : ClusterNodeIdentity::getServerId();
    }

    private static function assertNonEmptyUserId(string $userId): void
    {
        if (trim($userId) === '') {
            throw new ClusterRedisException('pushToUser requires a non-empty user_id');
        }
    }
}
