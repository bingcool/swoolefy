<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Predis 集群 Redis 适配层（纯 PHP，无 ext-redis 时可用）。
 *
 * 命令语义与 PhpRedisClusterAdapter 对齐，返回结构差异由 PushStreamEntry 统一解析。
 *
 * @see ClusterRedisClient
 * @see PhpRedisClusterAdapter
 */
class PredisClusterAdapter implements ClusterRedisAdapterInterface
{
    private \Predis\Client $client;

    public function __construct(\Predis\Client $client)
    {
        $this->client = $client;
    }

    public function hMSet(string $key, array $data): void
    {
        $this->client->hmset($key, $data);
    }

    public function hSet(string $key, string $field, $value): void
    {
        $this->client->hset($key, $field, $value);
    }

    public function hGetAll(string $key)
    {
        $result = $this->client->hgetall($key);

        return is_array($result) ? $result : [];
    }

    public function hGetAllMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $pipe = $this->client->pipeline();
        foreach ($keys as $key) {
            $pipe->hgetall($key);
        }
        $rows = $pipe->execute();
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($keys as $index => $key) {
            $meta = $rows[$index] ?? [];
            if (is_array($meta) && $meta !== []) {
                $result[$key] = $meta;
            }
        }

        return $result;
    }

    public function expire(string $key, int $ttl): void
    {
        $this->client->expire($key, $ttl);
    }

    public function del(string $key): void
    {
        $this->client->del([$key]);
    }

    public function setEx(string $key, int $ttl, string $value): void
    {
        $this->client->setex($key, $ttl, $value);
    }

    public function setNxEx(string $key, int $ttl, string $value = '1'): bool
    {
        return (string) $this->client->set($key, $value, 'EX', $ttl, 'NX') === 'OK';
    }

    public function exists(string $key): bool
    {
        return (bool) $this->client->exists($key);
    }

    public function sAdd(string $key, string $member): void
    {
        $this->client->sadd($key, [$member]);
    }

    public function sRem(string $key, string $member): void
    {
        $this->client->srem($key, [$member]);
    }

    public function sMembers(string $key)
    {
        $result = $this->client->smembers($key);

        return is_array($result) ? $result : [];
    }

    public function sCard(string $key): int
    {
        return (int) $this->client->scard($key);
    }

    public function zAdd(string $key, $score, string $member): void
    {
        $this->client->zadd($key, [$member => $score]);
    }

    public function zRem(string $key, string $member): void
    {
        $this->client->zrem($key, [$member]);
    }

    public function zRangeByScore(string $key, string $start, string $end)
    {
        $result = $this->client->zrangebyscore($key, $start, $end);

        return is_array($result) ? $result : [];
    }

    public function publish(string $channel, string $message)
    {
        return $this->client->publish($channel, $message);
    }

    public function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $pipe = $this->client->pipeline();
        foreach ($items as $item) {
            $pipe->publish((string) $item[0], (string) $item[1]);
        }
        $pipe->execute();
    }

    public function rPush(string $key, string $value): void
    {
        $this->client->rpush($key, [$value]);
    }

    public function lPop(string $key): ?string
    {
        $result = $this->client->lpop($key);
        if ($result === null) {
            return null;
        }

        return (string) $result;
    }

    public function lTrim(string $key, int $start, int $end): void
    {
        $this->client->ltrim($key, $start, $end);
    }

    public function brPop(string $key, int $timeoutSeconds): ?string
    {
        $result = $this->client->brpop([$key], $timeoutSeconds);
        if (!is_array($result)) {
            return null;
        }

        $payload = $result[$key] ?? $result[1] ?? $result[array_key_last($result)] ?? null;

        return $payload === null ? null : (string) $payload;
    }

    public function xAdd(string $key, array $fields, int $maxLen = 0): string
    {
        if ($maxLen > 0) {
            $entryId = $this->client->xadd($key, $fields, 'MAXLEN', '~', (string) $maxLen, '*');
        } else {
            $entryId = $this->client->xadd($key, $fields, '*');
        }

        return (string) $entryId;
    }

    public function xGroupCreate(string $key, string $group, bool $mkStream = true): void
    {
        try {
            $arguments = ['CREATE', $key, $group, '0'];
            if ($mkStream) {
                $arguments[] = 'MKSTREAM';
            }
            $this->client->xgroup(...$arguments);
        } catch (\Predis\Response\ServerException $exception) {
            if (stripos($exception->getMessage(), 'BUSYGROUP') === false) {
                throw $exception;
            }
        }
    }

    public function xReadGroup(
        string $group,
        string $consumer,
        string $streamKey,
        int $count,
        int $blockMs,
        string $id = '>'
    ): array {
        try {
            $result = $this->client->xreadgroup(
                'GROUP',
                $group,
                $consumer,
                'COUNT',
                (string) $count,
                'BLOCK',
                (string) $blockMs,
                'STREAMS',
                $streamKey,
                $id
            );
        } catch (\Predis\Response\ServerException $exception) {
            return [];
        }

        if (!is_array($result)) {
            return [];
        }

        return PushStreamEntry::fromXReadGroupResult($result, $streamKey);
    }

    public function xAutoClaim(
        string $key,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start,
        int $count
    ): array {
        try {
            $result = $this->client->xautoclaim(
                $key,
                $group,
                $consumer,
                (string) $minIdleMs,
                $start,
                'COUNT',
                (string) $count
            );
        } catch (\Throwable $throwable) {
            return ['0-0', []];
        }

        if (!is_array($result)) {
            return ['0-0', []];
        }

        return PushStreamEntry::fromXAutoClaimResult($result);
    }

    public function xAck(string $key, string $group, array $entryIds): int
    {
        if ($entryIds === []) {
            return 0;
        }

        return (int) $this->client->xack($key, $group, $entryIds);
    }

    public function xPendingCount(string $key, string $group): int
    {
        try {
            $result = $this->client->xpending($key, $group);
        } catch (\Throwable $throwable) {
            return 0;
        }

        if (!is_array($result)) {
            return 0;
        }

        if (isset($result[0]) && is_numeric($result[0])) {
            return (int) $result[0];
        }

        return (int) ($result['count'] ?? $result['pending'] ?? 0);
    }

    public function xAddMany(array $items, int $maxLen = 0): void
    {
        if ($items === []) {
            return;
        }

        $pipe = $this->client->pipeline();
        foreach ($items as $item) {
            $streamKey = (string) $item[0];
            $payload = (string) $item[1];
            if ($maxLen > 0) {
                $pipe->xadd($streamKey, ['payload' => $payload], 'MAXLEN', '~', (string) $maxLen, '*');
            } else {
                $pipe->xadd($streamKey, ['payload' => $payload], '*');
            }
        }
        $pipe->execute();
    }

    public function ping()
    {
        return $this->client->ping();
    }

    public function close(): void
    {
        $this->client->disconnect();
    }
}
