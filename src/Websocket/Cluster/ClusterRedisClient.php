<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis 客户端封装，支持 ext-redis (phpredis) 与 predis/predis。
 *
 * 驱动选择（cluster.redis.client）：
 * - auto（默认）：优先 phpredis，否则 Predis
 * - phpredis：强制 ext-redis
 * - predis：强制 Predis
 *
 * Swoole 下使用 phpredis 时依赖 hook_flags（默认 SWOOLE_HOOK_ALL）协程化阻塞 IO。
 *
 * - execute()：Worker 进程内复用长连接（失败自动重连），供注册表与 XADD/PUBLISH 发布
 * - runDedicated()：独立连接，供 Stream XREADGROUP / List BRPOP 等阻塞读
 * - subscribe()：Pub/Sub 长连接（仅 transport=pubsub）
 */
class ClusterRedisClient
{
    private const DRIVER_PHPREDIS = 'phpredis';
    private const DRIVER_PREDIS = 'predis';

    /** @var ClusterRedisAdapterInterface|null Worker 进程内复用的 Redis 连接 */
    private static ?ClusterRedisAdapterInterface $sharedAdapter = null;

    /**
     * 在复用连接上执行 Redis 命令；连接异常时自动重建并重试一次。
     */
    public static function execute(callable $callback)
    {
        try {
            return $callback(self::getSharedAdapter());
        } catch (\Throwable $throwable) {
            self::resetSharedAdapter();

            return $callback(self::getSharedAdapter());
        }
    }

    /** 关闭复用连接（单测 teardown 或 Worker 退出时调用） */
    public static function resetSharedAdapter(): void
    {
        if (self::$sharedAdapter !== null) {
            self::$sharedAdapter->close();
            self::$sharedAdapter = null;
        }
    }

    /**
     * 在独立 Redis 连接上执行阻塞消费（BRPOP / SUBSCRIBE 等），不与 execute() 复用连接混用。
     */
    public static function runDedicated(callable $callback): void
    {
        $adapter = self::createAdapter();
        try {
            $callback($adapter);
        } finally {
            $adapter->close();
        }
    }

    public static function subscribe(string $channel, callable $onMessage): void
    {
        if (self::resolveDriver() === self::DRIVER_PHPREDIS) {
            self::subscribePhpRedis($channel, $onMessage);

            return;
        }

        self::subscribePredis($channel, $onMessage);
    }

    private static function getSharedAdapter(): ClusterRedisAdapterInterface
    {
        if (self::$sharedAdapter === null) {
            self::$sharedAdapter = self::createAdapter();
        }

        return self::$sharedAdapter;
    }

    private static function createAdapter(): ClusterRedisAdapterInterface
    {
        if (self::resolveDriver() === self::DRIVER_PHPREDIS) {
            return new PhpRedisClusterAdapter(self::connectPhpRedis());
        }

        return new PredisClusterAdapter(self::connectPredis());
    }

    private static function resolveDriver(): string
    {
        $preferred = strtolower((string) (ClusterConfig::redis()['client'] ?? 'auto'));

        if ($preferred === self::DRIVER_PHPREDIS) {
            self::assertPhpRedisAvailable();

            return self::DRIVER_PHPREDIS;
        }

        if ($preferred === self::DRIVER_PREDIS) {
            self::assertPredisAvailable();

            return self::DRIVER_PREDIS;
        }

        if ($preferred !== 'auto' && $preferred !== '') {
            throw new ClusterRedisException(
                'Invalid cluster.redis.client: ' . $preferred . ' (allowed: auto, phpredis, predis)'
            );
        }

        if (class_exists(\Redis::class)) {
            return self::DRIVER_PHPREDIS;
        }

        if (class_exists(\Predis\Client::class)) {
            return self::DRIVER_PREDIS;
        }

        throw new ClusterRedisException(
            'Redis client unavailable: install ext-redis or predis/predis (composer require predis/predis)'
        );
    }

    private static function subscribePhpRedis(string $channel, callable $onMessage): void
    {
        self::assertPhpRedisAvailable();

        $redis = self::connectPhpRedis();
        // SUBSCRIBE 长连接：读超时 -1，断线后由订阅进程外层循环重连
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        try {
            $redis->subscribe([$channel], function (\Redis $redis, string $chan, string $message) use ($onMessage) {
                $onMessage((string) $message);
            });
        } finally {
            $redis->close();
        }
    }

    private static function subscribePredis(string $channel, callable $onMessage): void
    {
        self::assertPredisAvailable();

        $client = self::connectPredis(true);
        try {
            $pubsub = $client->pubSubLoop();
            $pubsub->subscribe($channel);
            foreach ($pubsub as $message) {
                if ($message->kind === 'message') {
                    $onMessage((string) $message->payload);
                }
            }
        } finally {
            $client->disconnect();
        }
    }

    private static function assertPhpRedisAvailable(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new ClusterRedisException('ext-redis extension is required (cluster.redis.client=phpredis)');
        }
    }

    private static function assertPredisAvailable(): void
    {
        if (!class_exists(\Predis\Client::class)) {
            throw new ClusterRedisException('predis/predis is required (composer require predis/predis)');
        }
    }

    private static function connectPhpRedis(): \Redis
    {
        $config = ClusterConfig::redis();
        $redis = new \Redis();
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 6379);
        $timeout = (float) ($config['timeout'] ?? 2);

        if (!$redis->connect($host, $port, $timeout)) {
            throw new ClusterRedisException('Redis connect failed');
        }

        self::authenticatePhpRedis($redis, $config);
        self::selectPhpRedisDatabase($redis, $config);

        return $redis;
    }

    private static function connectPredis(bool $forSubscribe = false): \Predis\Client
    {
        $config = ClusterConfig::redis();
        $timeout = (float) ($config['timeout'] ?? 2);
        $parameters = [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 6379),
            'database' => (int) ($config['database'] ?? 0),
            'read_write_timeout' => $forSubscribe ? 0 : $timeout,
        ];

        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            $parameters['password'] = $password;
        }

        return new \Predis\Client($parameters);
    }

    private static function authenticatePhpRedis(\Redis $redis, array $config): void
    {
        $password = (string) ($config['password'] ?? '');
        if ($password !== '' && !$redis->auth($password)) {
            throw new ClusterRedisException('Redis auth failed');
        }
    }

    private static function selectPhpRedisDatabase(\Redis $redis, array $config): void
    {
        $database = (int) ($config['database'] ?? 0);
        if ($database > 0 && !$redis->select($database)) {
            throw new ClusterRedisException('Redis select database failed');
        }
    }
}
