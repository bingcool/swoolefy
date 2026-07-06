<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 推送去重（Redis SET + TTL）。
 *
 * ## 背景
 *
 * PushMessage.msg_id 标识一条集群推送指令。Streams XAUTOCLAIM / 网络重试可能导致
 * 同一条 payload 被消费进程多次投递，客户端收到重复 push。
 *
 * ## 策略
 *
 * 1. 投递前 `exists(dedup:{msg_id})` → 已存在则跳过投递并 ACK
 * 2. 投递成功且 `shouldAck()` 时 `SET dedup:{msg_id} 1 EX ttl`
 * 3. 投递失败不 mark，保留 PEL 重试
 *
 * Redis 不可用时 fail-open（不去重），避免阻断推送。
 *
 * @see PushDeliveryHandler
 * @see ClusterConfig::pushDedupSettings()
 */
class PushDedupStore
{
    /** @var array<string, int>|null 单测内存模拟：msg_id => 过期 Unix 时间戳 */
    private static ?array $memoryStore = null;

    /** 单测启用进程内去重，不访问 Redis */
    public static function useMemoryStoreForTest(): void
    {
        self::$memoryStore = [];
    }

    public static function resetForTest(): void
    {
        self::$memoryStore = null;
    }

    public static function isEnabled(): bool
    {
        if (!ClusterConfig::isEnabled()) {
            return false;
        }

        $dedup = ClusterConfig::pushDedupSettings();

        return !empty($dedup['enable']);
    }

    public static function ttl(): int
    {
        return max(60, (int) (ClusterConfig::pushDedupSettings()['ttl'] ?? 86400));
    }

    public static function extractMsgId(array $message): string
    {
        return trim((string) ($message['msg_id'] ?? ''));
    }

    /** 是否已处理过该 msg_id（重复投递应跳过） */
    public static function isDuplicate(string $msgId): bool
    {
        if ($msgId === '' || !self::isEnabled()) {
            return false;
        }

        if (self::$memoryStore !== null) {
            $expiresAt = self::$memoryStore[$msgId] ?? 0;

            return $expiresAt > time();
        }

        try {
            return (bool) ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis) use ($msgId) {
                return $redis->exists(self::key($msgId));
            });
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /** 标记 msg_id 已处理（仅 shouldAck 成功后调用） */
    public static function markProcessed(string $msgId): void
    {
        if ($msgId === '' || !self::isEnabled()) {
            return;
        }

        $ttl = self::ttl();
        if (self::$memoryStore !== null) {
            self::$memoryStore[$msgId] = time() + $ttl;

            return;
        }

        try {
            ClusterRedisClient::execute(static function (ClusterRedisAdapterInterface $redis) use ($msgId, $ttl) {
                $redis->setEx(self::key($msgId), $ttl, '1');
            });
        } catch (\Throwable $throwable) {
            // Redis 失败不影响主流程
        }
    }

    private static function key(string $msgId): string
    {
        return ClusterConfig::keyPrefix() . 'push:dedup:' . $msgId;
    }
}
