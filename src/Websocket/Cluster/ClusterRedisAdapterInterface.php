<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 集群 Redis 命令适配接口（phpredis / Predis 统一门面）。
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

    public function ping();

    public function close(): void;
}
