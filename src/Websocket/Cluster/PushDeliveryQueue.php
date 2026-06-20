<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Pub/Sub 模式下的本节点投递缓冲队列（Redis List）。
 *
 * 仅 transport=pubsub 且 delivery_process_num>1 时使用：
 * - WebsocketPushSubscriberProcess SUBSCRIBE 后 RPUSH
 * - N 个 WebsocketPushDeliveryProcess BRPOP 竞争消费
 *
 * streams 模式不需要此队列（Stream + 消费组已提供持久化与并行）。
 */
class PushDeliveryQueue
{
    /** SUBSCRIBE 回调内快速入队，避免阻塞在 server->push() 上 */
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
     * BRPOP 阻塞出队（需在 runDedicated 独立连接上调用）。
     */
    public static function dequeueBlocking(ClusterRedisAdapterInterface $redis, int $timeoutSeconds = 5): ?string
    {
        return $redis->brPop(ClusterConfig::pushDeliveryQueueKey(), $timeoutSeconds);
    }
}
