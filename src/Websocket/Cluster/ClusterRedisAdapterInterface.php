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

    public function close(): void;
}
