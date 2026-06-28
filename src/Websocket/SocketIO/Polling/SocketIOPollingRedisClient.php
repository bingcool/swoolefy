<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;
use Swoolefy\Websocket\Cluster\ClusterRedisClient;

/**
 * polling 共享存储的 Redis 阻塞读（BRPOP）须在独立连接上执行。
 */
class SocketIOPollingRedisClient
{
    public static function runDedicated(callable $callback): void
    {
        ClusterRedisClient::runDedicated($callback);
    }
}
