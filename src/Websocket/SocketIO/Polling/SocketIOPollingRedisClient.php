<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;

/**
 * polling 场景下的 Redis 阻塞读专用客户端。
 *
 * ## 为何单独封装
 *
 * long-polling 的 GET 请求会在 Worker 内阻塞等待出站包（Redis BRPOP，最长 poll_timeout 秒）。
 * 该阻塞读 **不能** 与 {@see ClusterRedisClient::execute()} 复用的协程/应用内单例连接混用：
 *
 * - 同一连接上 BRPOP 会占用读循环，导致同连接上的 HGET/RPUSH 等命令饿死或死锁
 * - Pub/Sub、Stream XREADGROUP 等同理，框架已在 ClusterRedisClient 提供 runDedicated()
 *
 * 本类是对 runDedicated 的语义化别名，供 {@see SocketIOPollingOutboundStore::blockingPop()} 调用，
 * 使 polling 模块不直接依赖 Cluster 包内部命名，便于阅读与单测 mock。
 *
 * ## 使用约定
 *
 * - **非阻塞** 读写（register / enqueue / drain / exists）：走 ClusterRedisClient::execute()
 * - **阻塞** 读（long-poll BRPOP）：走本类 runDedicated()，回调结束后连接自动 close
 *
 * @see ClusterRedisClient::runDedicated()
 * @see SocketIOPollingOutboundStore::blockingPop()
 */
class SocketIOPollingRedisClient
{
    /**
     * 在独立 Redis 连接上执行回调（通常内含 brPop）。
     *
     * 连接生命周期：创建 → 执行 $callback → finally close，不与 execute() 池化连接共享。
     *
     * @param callable(ClusterRedisAdapterInterface): void $callback
     */
    public static function runDedicated(callable $callback): void
    {
        ClusterRedisClient::runDedicated($callback);
    }
}
