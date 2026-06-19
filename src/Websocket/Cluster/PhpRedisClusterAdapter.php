<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * ext-redis (phpredis) 适配层。
 */
class PhpRedisClusterAdapter implements ClusterRedisAdapterInterface
{
    private \Redis $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function hMSet(string $key, array $data): void
    {
        $this->redis->hMSet($key, $data);
    }

    public function hSet(string $key, string $field, $value): void
    {
        $this->redis->hSet($key, $field, $value);
    }

    public function hGetAll(string $key)
    {
        $result = $this->redis->hGetAll($key);

        return is_array($result) ? $result : [];
    }

    public function hGetAllMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($keys as $key) {
            $pipe->hGetAll($key);
        }
        $rows = $pipe->exec();
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
        $this->redis->expire($key, $ttl);
    }

    public function del(string $key): void
    {
        $this->redis->del($key);
    }

    public function sAdd(string $key, string $member): void
    {
        $this->redis->sAdd($key, $member);
    }

    public function sRem(string $key, string $member): void
    {
        $this->redis->sRem($key, $member);
    }

    public function sMembers(string $key)
    {
        $result = $this->redis->sMembers($key);

        return is_array($result) ? $result : [];
    }

    public function sCard(string $key): int
    {
        return (int) $this->redis->sCard($key);
    }

    public function zAdd(string $key, $score, string $member): void
    {
        $this->redis->zAdd($key, $score, $member);
    }

    public function zRem(string $key, string $member): void
    {
        $this->redis->zRem($key, $member);
    }

    public function zRangeByScore(string $key, string $start, string $end)
    {
        $result = $this->redis->zRangeByScore($key, $start, $end);

        return is_array($result) ? $result : [];
    }

    public function publish(string $channel, string $message)
    {
        return $this->redis->publish($channel, $message);
    }

    public function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($items as $item) {
            $pipe->publish((string) $item[0], (string) $item[1]);
        }
        $pipe->exec();
    }

    public function ping()
    {
        return $this->redis->ping();
    }

    public function close(): void
    {
        $this->redis->close();
    }
}
