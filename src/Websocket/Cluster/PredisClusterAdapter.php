<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Predis 适配层（纯 PHP，无 ext-redis 时可用）。
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

    public function expire(string $key, int $ttl): void
    {
        $this->client->expire($key, $ttl);
    }

    public function del(string $key): void
    {
        $this->client->del([$key]);
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

    public function close(): void
    {
        $this->client->disconnect();
    }
}
