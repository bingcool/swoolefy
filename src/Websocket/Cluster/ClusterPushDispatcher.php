<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 集群模式推送分发器：
 * 1. 从 Redis 查全局 conn_id
 * 2. 按 server_id 分组
 * 3. 本节点直接投递，远端节点走 Redis Pub/Sub 精准扇出
 */
class ClusterPushDispatcher implements PushDispatcherInterface
{
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        $connId = WebsocketConnectionManager::getConnIdByFd($fd);
        if ($connId === '') {
            // 无 conn_id 时降级为本机直推（兼容未登记连接）
            return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
        }

        $meta = RedisConnectionRegistry::getConnectionMeta($connId);
        if ($meta === null) {
            return WebsocketConnectionManager::deliverEventToFdLocally($server, $fd, $event, $data);
        }

        return $this->fanout(
            $server,
            [[
                'fd' => (int) ($meta['fd'] ?? $fd),
                'conn_id' => $connId,
                'server_id' => (string) ($meta['server_id'] ?? ''),
            ]],
            $event,
            $data
        ) > 0;
    }

    public function pushEventToUser(Server $server, string $userId, string $event, $data = []): int
    {
        // 跨节点 pushToUser：先查 Redis 全局用户索引，再按节点扇出
        $connIds = RedisConnectionRegistry::getConnIdsByUser($userId);

        return $this->fanoutByConnIds($server, $connIds, $event, $data);
    }

    public function pushEventToRoom(Server $server, string $room, string $event, $data = []): int
    {
        // 跨节点 pushToRoom：先查 Redis 全局房间索引，再按节点扇出
        $connIds = RedisConnectionRegistry::getConnIdsByRoom($room);

        return $this->fanoutByConnIds($server, $connIds, $event, $data);
    }

    public function broadcastEvent(Server $server, string $event, $data = []): int
    {
        $localServerId = ClusterNodeIdentity::getServerId();
        $message = PushMessage::broadcast($event, $data, $localServerId);
        // 广播：本节点遍历本地 Table 投递，其余节点各发一条 broadcast 指令
        $count = PushDeliveryHandler::deliver($server, $message);

        foreach (RedisConnectionRegistry::getAllNodeIds() as $serverId) {
            if ($serverId === $localServerId) {
                continue;
            }
            // 按 server_id 频道精准扇出，避免所有节点收到全量消息
            RedisConnectionRegistry::publish($serverId, $message);
        }

        return $count;
    }

    private function fanoutByConnIds(Server $server, array $connIds, string $event, $data): int
    {
        $targets = [];
        foreach (array_unique($connIds) as $connId) {
            if (!is_string($connId) || $connId === '') {
                continue;
            }
            $meta = RedisConnectionRegistry::getConnectionMeta($connId);
            if ($meta === null) {
                continue;
            }
            $targets[] = [
                'fd' => (int) ($meta['fd'] ?? 0),
                'conn_id' => $connId,
                'server_id' => (string) ($meta['server_id'] ?? ''),
            ];
        }

        return $this->fanout($server, $targets, $event, $data);
    }

    private function fanout(Server $server, array $targets, string $event, $data): int
    {
        if (empty($targets)) {
            return 0;
        }

        $localServerId = ClusterNodeIdentity::getServerId();
        $grouped = [];
        foreach ($targets as $target) {
            $serverId = (string) ($target['server_id'] ?? '');
            if ($serverId === '') {
                // server_id 缺失时从 conn_id（server_id:fd）反解
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
        foreach ($grouped as $serverId => $serverTargets) {
            // 只传 event+data，编码在投递时按连接 is_socketio 决定
            $message = PushMessage::event($serverTargets, $event, $data, $localServerId);
            if ($serverId === $localServerId) {
                $count += PushDeliveryHandler::deliver($server, $message);
                continue;
            }
            RedisConnectionRegistry::publish($serverId, $message);
            $count += count($serverTargets);
        }

        return $count;
    }
}
