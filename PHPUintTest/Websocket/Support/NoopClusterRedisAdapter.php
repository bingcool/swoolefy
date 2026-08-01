<?php

namespace PHPUintTest\Websocket\Support;

use Swoolefy\Websocket\Cluster\ClusterRedisAdapterInterface;

/**
 * 单测用 ClusterRedisAdapterInterface 空实现基类。
 *
 * 新增 Redis 接口方法时在此补默认实现，避免各测试文件 mock 漏方法 fatal。
 */
class NoopClusterRedisAdapter implements ClusterRedisAdapterInterface
{
    public function hMSet(string $key, array $data): void
    {
    }

    public function hSet(string $key, string $field, $value): void
    {
    }

    public function hGetAll(string $key)
    {
        return [];
    }

    public function hGetAllMany(array $keys): array
    {
        return [];
    }

    public function expire(string $key, int $ttl): void
    {
    }

    public function del(string $key): void
    {
    }

    public function setEx(string $key, int $ttl, string $value): void
    {
    }

    public function setNxEx(string $key, int $ttl, string $value = '1'): bool
    {
        return true;
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function sAdd(string $key, string $member): void
    {
    }

    public function sRem(string $key, string $member): void
    {
    }

    public function sMembers(string $key)
    {
        return [];
    }

    public function sCard(string $key): int
    {
        return 0;
    }

    public function zAdd(string $key, $score, string $member): void
    {
    }

    public function zRem(string $key, string $member): void
    {
    }

    public function zRangeByScore(string $key, string $start, string $end)
    {
        return [];
    }

    public function publish(string $channel, string $message)
    {
        return 0;
    }

    public function publishMany(array $items): void
    {
    }

    public function rPush(string $key, string $value): void
    {
    }

    public function lPop(string $key): ?string
    {
        return null;
    }

    public function lTrim(string $key, int $start, int $end): void
    {
    }

    public function brPop(string $key, int $timeoutSeconds): ?string
    {
        return null;
    }

    public function xAdd(string $key, array $fields, int $maxLen = 0): string
    {
        return '0-1';
    }

    public function xGroupCreate(string $key, string $group, bool $mkStream = true): void
    {
    }

    public function xReadGroup(
        string $group,
        string $consumer,
        string $streamKey,
        int $count,
        int $blockMs,
        string $id = '>'
    ): array {
        return [];
    }

    public function xAutoClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start,
        int $count
    ): array {
        return ['0-0', []];
    }

    public function xAck(string $key, string $group, array $entryIds): int
    {
        return count($entryIds);
    }

    public function xPendingCount(string $key, string $group): int
    {
        return 0;
    }

    public function xAddMany(array $items, int $maxLen = 0): void
    {
    }

    public function ping()
    {
        return true;
    }

    public function close(): void
    {
    }
}
