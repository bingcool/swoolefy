<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 本节点推送投递本地队列（Redis List）。
 *
 * 多投递进程模式下：订阅进程 RPUSH，各投递进程 BRPOP 竞争消费，保证每条消息只投递一次。
 */
class PushDeliveryQueue
{
    public static function enqueue(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $queueKey = ClusterConfig::pushDeliveryQueueKey();
        ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis) use ($queueKey, $payload) {
            $redis->rPush($queueKey, $payload);
        });
    }

    /**
     * 阻塞弹出一条队列消息（需在独立 Redis 连接上调用）。
     */
    public static function dequeueBlocking(ClusterRedisAdapterInterface $redis, int $timeoutSeconds = 5): ?string
    {
        return $redis->brPop(ClusterConfig::pushDeliveryQueueKey(), $timeoutSeconds);
    }
}
