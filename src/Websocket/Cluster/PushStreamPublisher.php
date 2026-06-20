<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis Streams 推送发布端（XADD）。
 *
 * ## 为何用 Streams 替代 Pub/Sub
 *
 * Pub/Sub 是「发时有人在听才收到」：消费进程崩溃或重启期间的消息**永久丢失**。
 * Streams 将消息写入 Redis，消费组通过 XREADGROUP 拉取，投递成功后 XACK；
 * 未 ACK 的留在 PEL（Pending Entries List），可由 XAUTOCLAIM 在进程恢复后重投。
 *
 * ## 数据流
 *
 * ```
 * ClusterPushBus::fanout
 *   → RedisConnectionRegistry::publish / publishMany
 *   → PushStreamPublisher::publish（本类）
 *   → XADD {key_prefix}push:stream:{目标 server_id}  field payload=JSON
 * ```
 *
 * 每个 WebSocket 节点一条 Stream（按 server_id 隔离），避免全集群共用一个 Stream 造成无效竞争。
 *
 * @see PushStreamConsumer
 * @see ClusterConfig::pushStreamKeyForServer()
 */
class PushStreamPublisher
{
    /**
     * 向目标节点 Stream 写入一条推送指令。
     *
     * @param string $serverId 目标节点 server_id（非本机）
     * @param array  $message  PushMessage::event / broadcast 结构
     *
     * @return string Redis entry id，可用于排查与对账
     */
    public static function publish(string $serverId, array $message): string
    {
        $streamKey = ClusterConfig::pushStreamKeyForServer($serverId);
        $payload = PushMessage::encode($message);
        $maxLen = ClusterConfig::pushStreamMaxLen();

        return (string) ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis) use ($streamKey, $payload, $maxLen) {
            // MAXLEN ~ 近似裁剪，防止 Stream 在消费慢于生产时撑爆 Redis 内存
            return $redis->xAdd($streamKey, [PushStreamEntry::FIELD_PAYLOAD => $payload], $maxLen);
        });
    }

    /**
     * 批量 XADD（pushToGroup 扇出到多节点时 pipeline 一次往返）。
     *
     * @param array<int, array{0: string, 1: array}> $items [serverId, message]
     */
    public static function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $maxLen = ClusterConfig::pushStreamMaxLen();
        $streamItems = [];
        foreach ($items as $item) {
            $serverId = (string) ($item[0] ?? '');
            $message = $item[1] ?? null;
            if ($serverId === '' || !is_array($message)) {
                continue;
            }
            $streamItems[] = [
                ClusterConfig::pushStreamKeyForServer($serverId),
                PushMessage::encode($message),
            ];
        }

        if ($streamItems === []) {
            return;
        }

        ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis) use ($streamItems, $maxLen) {
            $redis->xAddMany($streamItems, $maxLen);
        });
    }
}
