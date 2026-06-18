<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 外部进程推送入口（HTTP / CLI / 队列消费者）。
 *
 * 与 WebsocketClusterPublisher 的区别：
 * - 本类：不依赖 Swfy::getServer()，$localServer 恒为 null，全部走 Redis Pub/Sub
 * - WebsocketClusterPublisher：仅 WebSocket Worker 内可用，本节点可直推
 *
 * 前置条件：
 * 1. Config/websocket.php → cluster.enable=true
 * 2. 定义 APP_NAME / APP_PATH（或 ClusterConfig::setWebsocketOverride）
 * 3. 各 WebSocket 节点 WebsocketPushSubscriberProcess 已启动
 * 4. 外部进程与 WebSocket 服务共用同一 Redis 与 key_prefix / channel_prefix
 *
 * 返回值：命中的连接数（或广播涉及的节点数），非「客户端 ACK」数。
 */
class ExternalPushPublisher
{
    /** 向 Redis room 索引下的所有连接扇出（跨节点） */
    public static function pushToRoom(string $room, string $event, $data = []): int
    {
        // null = 外部进程，不走本机 server->push()
        return ClusterPushBus::publishToRoom($room, $event, $data, null);
    }

    /** 向 Redis user 索引下的所有连接扇出（同一用户多端在线） */
    public static function pushToUser(string $userId, string $event, $data = []): int
    {
        return ClusterPushBus::publishToUser($userId, $event, $data, null);
    }

    /** 向 nodes 集合中每个在线节点发一条 broadcast 指令 */
    public static function broadcast(string $event, $data = []): int
    {
        return ClusterPushBus::publishBroadcast($event, $data, null);
    }
}
