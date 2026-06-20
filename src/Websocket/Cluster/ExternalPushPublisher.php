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
 * 前置条件：
 * 1. Config/websocket.php → cluster.enable=true
 * 2. 定义 APP_NAME / APP_PATH（或 ClusterConfig::setWebsocketOverride）
 * 3. 各 WebSocket 节点推送消费进程已启动（streams 或 pubsub）
 * 4. 外部进程与 WebSocket 服务共用同一 Redis 与 key_prefix
 *
 * 推送总线默认 Redis Streams（XADD 持久化）；transport=pubsub 时为 PUBLISH。
 *
 * ## 引用模式（msg_id）
 *
 * $data 可只传 `{ "msg_id": "m-1001" }` 等轻量引用；各 WebSocket 节点收到 Pub/Sub 后，
 * 由 Config/websocket.php → push.enricher 在 server->push() 前查库组装完整 message。
 * 示例：ExternalPushPublisher::pushToUser('user-b', 'chat.private', ['msg_id' => 'm-1001']);
 *
 * 返回值：命中的连接数（或广播涉及的节点数），非「客户端 ACK」数。
 */
class ExternalPushPublisher
{
    /** 向 Redis group 索引下的所有连接扇出（跨节点） */
    public static function pushToGroup(string $group, string $event, $data = []): int
    {
        // null = 外部进程，不走本机 server->push()
        return ClusterPushBus::publishToGroup($group, $event, $data, null);
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
