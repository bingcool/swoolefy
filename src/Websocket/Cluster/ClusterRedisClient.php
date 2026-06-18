<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis 客户端封装，兼容：
 * - Swoole 协程内：Coroutine\Redis 短连接
 * - CLI/HTTP（无协程）：Coroutine\run 或 ext-redis
 * - 订阅进程：Coroutine\Redis 长连接 SUBSCRIBE
 */
class ClusterRedisClient
{
    public static function execute(callable $callback)
    {
        // 已在协程内（WebSocket Worker）→ 短连接 Coroutine\Redis
        if (\Swoole\Coroutine::getCid() > 0) {
            return self::executeCoroutine($callback);
        }

        // 有 Swoole 扩展但不在协程（如同步 CLI）→ Coroutine\run 包一层
        if (extension_loaded('swoole')) {
            $result = null;
            $exception = null;
            \Swoole\Coroutine\run(function () use ($callback, &$result, &$exception) {
                try {
                    $result = self::executeCoroutine($callback);
                } catch (\Throwable $e) {
                    $exception = $e;
                }
            });
            if ($exception !== null) {
                throw $exception;
            }

            return $result;
        }

        // PHP-FPM 等无 Swoole 环境 → ext-redis + PhpRedisClusterAdapter
        if (class_exists(\Redis::class)) {
            return self::executePhpRedis($callback);
        }

        throw new ClusterRedisException('Redis client unavailable: need ext-swoole or ext-redis');
    }

    public static function subscribe(string $channel, callable $onMessage): void
    {
        $redis = self::connectCoroutine();
        // SUBSCRIBE 阻塞协程，timeout=-1 保持长连接
        $redis->setOptions([
            'timeout' => -1,
        ]);
        $redis->subscribe([$channel], function ($redis, $chan, $msg) use ($onMessage) {
            $onMessage((string) $msg);
        });
    }

    private static function executeCoroutine(callable $callback)
    {
        $redis = self::connectCoroutine();
        try {
            return $callback($redis);
        } finally {
            $redis->close();
        }
    }

    private static function executePhpRedis(callable $callback)
    {
        $adapter = new PhpRedisClusterAdapter(self::createPhpRedis());
        try {
            return $callback($adapter);
        } finally {
            $adapter->close();
        }
    }

    private static function connectCoroutine(): \Swoole\Coroutine\Redis
    {
        $config = ClusterConfig::redis();
        $redis = new \Swoole\Coroutine\Redis();
        $redis->setOptions([
            'connect_timeout' => (float) ($config['timeout'] ?? 2),
            'timeout' => (float) ($config['timeout'] ?? 2),
            'reconnect' => 2,
        ]);

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        if (!$redis->connect($host, $port)) {
            throw new ClusterRedisException('Redis connect failed: ' . $redis->errMsg);
        }

        $password = (string) ($config['password'] ?? '');
        if ($password !== '' && !$redis->auth($password)) {
            throw new ClusterRedisException('Redis auth failed: ' . $redis->errMsg);
        }

        $database = (int) ($config['database'] ?? 0);
        if ($database > 0 && !$redis->select($database)) {
            throw new ClusterRedisException('Redis select database failed: ' . $redis->errMsg);
        }

        return $redis;
    }

    private static function createPhpRedis(): \Redis
    {
        $config = ClusterConfig::redis();
        $redis = new \Redis();
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $timeout = (float) ($config['timeout'] ?? 2);

        if (!$redis->connect($host, $port, $timeout)) {
            throw new ClusterRedisException('Redis connect failed');
        }

        $password = (string) ($config['password'] ?? '');
        if ($password !== '' && !$redis->auth($password)) {
            throw new ClusterRedisException('Redis auth failed');
        }

        $database = (int) ($config['database'] ?? 0);
        if ($database > 0 && !$redis->select($database)) {
            throw new ClusterRedisException('Redis select database failed');
        }

        return $redis;
    }
}
