<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 集群 Redis 命令适配接口（phpredis / Predis 统一门面）。
 *
 * ClusterRedisClient 通过本接口屏蔽驱动差异；新增 Redis 能力时须两个 Adapter 同步实现。
 *
 * 命令分三类：
 * - **注册表**：Hash / Set / ZSET（RedisConnectionRegistry）
 * - **Pub/Sub + List**：publish / brPop（transport=pubsub）
 * - **Streams**：xAdd / xReadGroup / xAck 等（transport=streams，默认）
 *
 * @see PhpRedisClusterAdapter
 * @see PredisClusterAdapter
 * @see ClusterRedisClient
 */
interface ClusterRedisAdapterInterface
{
    public function hMSet(string $key, array $data): void;

    public function hSet(string $key, string $field, $value): void;

    public function hGetAll(string $key);

    /**
     * Pipeline 批量 HGETALL，返回 key => hash；不存在或空 hash 的 key 不在结果中。
     *
     * @param string[] $keys
     *
     * @return array<string, array<string, string>>
     */
    public function hGetAllMany(array $keys): array;

    public function expire(string $key, int $ttl): void;

    public function del(string $key): void;

    /** SET key value EX ttl（推送去重 markProcessed） */
    public function setEx(string $key, int $ttl, string $value): void;

    /** key 是否存在（推送去重 isDuplicate） */
    public function exists(string $key): bool;

    public function sAdd(string $key, string $member): void;

    public function sRem(string $key, string $member): void;

    public function sMembers(string $key);

    public function sCard(string $key): int;

    public function zAdd(string $key, $score, string $member): void;

    public function zRem(string $key, string $member): void;

    public function zRangeByScore(string $key, string $start, string $end);

    public function publish(string $channel, string $message);

    /**
     * Pipeline 批量 PUBLISH。
     *
     * @param array<int, array{0: string, 1: string}> $items [channel, message]
     */
    public function publishMany(array $items): void;

    public function rPush(string $key, string $value): void;

    /**
     * 阻塞弹出列表尾部元素。
     *
     * @return string|null 超时返回 null
     */
    public function brPop(string $key, int $timeoutSeconds): ?string;

    // -------------------------------------------------------------------------
    // Redis Streams（transport=streams 推送总线，详见 PushStreamPublisher / PushStreamConsumer）
    // -------------------------------------------------------------------------

    /**
     * XADD 写入 Stream 条目。
     *
     * @param int $maxLen >0 时近似 MAXLEN ~ 裁剪
     *
     * @return string entry id
     */
    public function xAdd(string $key, array $fields, int $maxLen = 0): string;

    /** XGROUP CREATE，组已存在时实现层应忽略 BUSYGROUP */
    public function xGroupCreate(string $key, string $group, bool $mkStream = true): void;

    /**
     * XREADGROUP GROUP ... STREAMS key id。
     *
     * @param string $id 新消息用 '>'；历史 pending 用 '0'
     *
     * @return array<int, array{id: string, payload: string}>
     */
    public function xReadGroup(
        string $group,
        string $consumer,
        string $streamKey,
        int $count,
        int $blockMs,
        string $id = '>'
    ): array;

    /**
     * XAUTOCLAIM：回收 PEL 中空闲超过 minIdleMs 的消息。
     *
     * @return array{0: string, 1: array<int, array{id: string, payload: string}>} [nextStart, entries]
     */
    public function xAutoClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start,
        int $count
    ): array;

    /** XACK：投递成功后确认，从 PEL 移除 */
    public function xAck(string $key, string $group, array $entryIds): int;

    /** XPENDING 汇总：返回消费组 pending 条数 */
    public function xPendingCount(string $key, string $group): int;

    /**
     * Pipeline 批量 XADD（跨节点扇出）。
     *
     * @param array<int, array{0: string, 1: string}> $items [streamKey, payloadJson]
     */
    public function xAddMany(array $items, int $maxLen = 0): void;

    public function ping();

    public function close(): void;
}
