<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;

/**
 * WebSocket 推送分发器接口（单机 / 集群统一抽象）。
 *
 * 业务层通过 PushDispatcherFactory::get() 获取实现，无需关心 cluster.enable 开关。
 *
 * @see LocalPushDispatcher    cluster.enable=false
 * @see ClusterPushDispatcher  cluster.enable=true
 */
interface PushDispatcherInterface
{
    /** 向指定 fd 推送事件，成功返回 true */
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool;

    /** 向用户下所有连接推送，返回成功投递数 */
    public function pushEventToUser(Server $server, string $userId, string $event, $data = []): int;

    /** 向小组下所有连接推送，返回成功投递数 */
    public function pushEventToGroup(Server $server, string $group, string $event, $data = []): int;

    /** 全节点广播，返回成功投递数（集群模式为涉及节点/连接数） */
    public function broadcastEvent(Server $server, string $event, $data = []): int;
}
