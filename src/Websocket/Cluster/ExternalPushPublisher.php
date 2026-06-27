<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Websocket\Offline\OfflineMessageCoordinator;

/**
 * 外部进程推送入口（HTTP / CLI / 队列消费者）。
 *
 * ## 与 WebsocketClusterPublisher 的区别
 *
 * | 类 | 运行环境 | $localServer | 本节点直推 |
 * |----|----------|--------------|------------|
 * | ExternalPushPublisher | HTTP/CLI/队列 | null | 否 |
 * | WebsocketClusterPublisher | WebSocket Worker | Swfy::getServer() | 是 |
 *
 * ## 前置条件
 *
 * 1. `Config/websocket.php` → `cluster.enable=true`
 * 2. 定义 `APP_NAME` / `APP_PATH`（或 `ClusterConfig::setWebsocketOverride()`）
 * 3. 各 WebSocket 节点推送消费进程已启动（streams 或 pubsub）
 * 4. 外部进程与 WebSocket 服务共用同一 Redis 与 `key_prefix`
 *
 * ## 推送总线
 *
 * 默认 `transport=streams`（XADD 持久化）；`transport=pubsub` 时为 PUBLISH（不持久化）。
 *
 * ## 引用模式（msg_id）
 *
 * `$data` 可只传 `{ "msg_id": "m-1001" }` 等轻量引用；各节点收到推送后，
 * 由 `Config/websocket.php` → `push.enricher` 在 `server->push()` 前查库组装完整 message。
 *
 * ```php
 * ExternalPushPublisher::pushToUser('user-b', 'chat.private', ['msg_id' => 'm-1001']);
 * ```
 *
 * **返回值**：命中的连接数（或 broadcast 涉及的节点数），非客户端 ACK 数。
 *
 * @see ClusterPushBus
 * @see WebsocketClusterPublisher
 */
class ExternalPushPublisher
{
    /** 向 Redis group 索引下的所有连接扇出（跨节点） */
    public static function pushToGroup(string $group, string $event, $data = []): int
    {
        $result = ClusterPushBus::publishToGroup($group, $event, $data, null);
        OfflineMessageCoordinator::maybeStoreOfflineAfterGroupPush($group, $event, $data, $result);

        return $result->reportedHitCount();
    }

    /**
     * 向 Redis user 索引下的所有连接扇出（同一用户多端在线）。
     *
     * @throws ClusterRedisException user_id 为空时（禁止匿名 pushToUser）
     */
    public static function pushToUser(string $userId, string $event, $data = []): int
    {
        if (trim($userId) === '') {
            throw new ClusterRedisException('pushToUser requires a non-empty user_id');
        }

        $result = ClusterPushBus::publishToUser($userId, $event, $data, null);
        OfflineMessageCoordinator::maybeStoreOfflineAfterPush($userId, $event, $data, $result);

        return $result->reportedHitCount();
    }

    /** 向 nodes 集合中每个在线节点发一条 broadcast 指令 */
    public static function broadcast(string $event, $data = []): int
    {
        $result = ClusterPushBus::publishBroadcast($event, $data, null);
        OfflineMessageCoordinator::maybeStoreOfflineAfterBroadcastPush($event, $data, $result);

        return $result->reportedHitCount();
    }
}
