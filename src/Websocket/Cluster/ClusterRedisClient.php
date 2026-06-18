<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 协程 Redis 客户端封装。
 * execute() 用于短连接注册/查询；subscribe() 用于推送订阅进程的长连接。
 */
class ClusterRedisClient
{
    public static function execute(callable $callback)
    {
        $redis = self::connect();
        try {
            return $callback($redis);
        } finally {
            $redis->close();
        }
    }

    public static function subscribe(string $channel, callable $onMessage): void
    {
        $redis = self::connect();
        // SUBSCRIBE 阻塞协程，timeout=-1 保持长连接
        $redis->setOptions([
            'timeout' => -1,
        ]);
        $redis->subscribe([$channel], function ($redis, $chan, $msg) use ($onMessage) {
            $onMessage((string) $msg);
        });
    }

    private static function connect(): \Swoole\Coroutine\Redis
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
}
