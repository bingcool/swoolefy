<?php

declare(strict_types=1);

namespace PhpUintTest\Websocket\Support;

use Swoolefy\Websocket\Cluster\ClusterRedisClient;
use Throwable;

/**
 * Redis 探活（@group redis 用例前置）。
 */
final class RedisAvailability
{
    public static function isAvailable(): bool
    {
        try {
            ClusterRedisClient::execute(static function ($redis) {
                return $redis->ping();
            });

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
