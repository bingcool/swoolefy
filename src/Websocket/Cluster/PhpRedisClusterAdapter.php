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

    public function rPush(string $key, string $value): void
    {
        $this->redis->rPush($key, $value);
    }

    public function brPop(string $key, int $timeoutSeconds): ?string
    {
        $result = $this->redis->brPop([$key], $timeoutSeconds);
        if (!is_array($result)) {
            return null;
        }

        $payload = $result[$key] ?? $result[1] ?? $result[array_key_last($result)] ?? null;

        return $payload === null ? null : (string) $payload;
    }

    public function xAdd(string $key, array $fields, int $maxLen = 0): string
    {
        $entryId = (string) $this->redis->xAdd($key, '*', $fields);
        if ($maxLen > 0) {
            // 近似裁剪：性能优于精确 MAXLEN，长度可能略超阈值
            if (method_exists($this->redis, 'xTrim')) {
                $this->redis->xTrim($key, $maxLen, true);
            } else {
                $this->redis->rawCommand('XTRIM', $key, 'MAXLEN', '~', (string) $maxLen);
            }
        }

        return $entryId;
    }

    public function xGroupCreate(string $key, string $group, bool $mkStream = true): void
    {
        try {
            $this->redis->xGroup('CREATE', $key, $group, '0', $mkStream);
        } catch (\RedisException $exception) {
            // 消费组已存在：多进程/重启时正常情况
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
        $result = $this->redis->xReadGroup($group, $consumer, [$streamKey => $id], $count, $blockMs);
        if ($result === false || !is_array($result)) {
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
        if (!method_exists($this->redis, 'xAutoClaim')) {
            // Redis < 6.2 无 XAUTOCLAIM，崩溃恢复能力受限，仍可读新消息
            return [$start, []];
        }

        $result = $this->redis->xAutoClaim($key, $group, $consumer, $minIdleMs, $start, $count);
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

        return (int) $this->redis->xAck($key, $group, $entryIds);
    }

    public function xAddMany(array $items, int $maxLen = 0): void
    {
        if ($items === []) {
            return;
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        foreach ($items as $item) {
            $pipe->xAdd((string) $item[0], '*', ['payload' => (string) $item[1]]);
        }
        $pipe->exec();

        if ($maxLen > 0) {
            $streamKeys = array_values(array_unique(array_map(static fn ($item) => (string) $item[0], $items)));
            foreach ($streamKeys as $streamKey) {
                if (method_exists($this->redis, 'xTrim')) {
                    $this->redis->xTrim($streamKey, $maxLen, true);
                } else {
                    $this->redis->rawCommand('XTRIM', $streamKey, 'MAXLEN', '~', (string) $maxLen);
                }
            }
        }
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
