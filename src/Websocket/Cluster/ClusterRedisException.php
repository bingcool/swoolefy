<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * WebSocket 集群模块运行时异常。
 *
 * 典型场景：
 * - Redis 连接/认证失败
 * - cluster.enable=false 时调用集群推送 API
 * - on_redis_failure=reject_open 时 onOpen 注册 Redis 失败
 * - WebsocketClusterPublisher 在非 WebSocket Worker 内调用
 */
class ClusterRedisException extends \RuntimeException
{
}
