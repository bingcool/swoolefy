<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Application;
use Swoolefy\Core\Dto\ContainerObjectDto;

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
 * - execute()：优先 EventApp 内 creatObject() 单例；无 App 时在协程内按 cid 缓存连接，
 *   协程结束时 Coroutine::defer 关闭，避免每次 execute 泄漏 TCP 连接
 * - runDedicated()：独立连接，供 Stream XREADGROUP / List BRPOP 等阻塞读
 * - subscribe()：Pub/Sub 长连接（仅 transport=pubsub）
 *
 * Worker open/message/close 与 polling HTTP 的框架 Redis 须在 EventApp 内调用 execute()；
 * 自定义推送进程同理使用 goApp / EventApp::registerApp。
 */
class ClusterRedisClient
{
    private const DRIVER_PHPREDIS = 'phpredis';
    private const DRIVER_PREDIS = 'predis';

    /** @var string 协程单例 phpredis 组件别名，可在app.php 中配置连接池
     * ClusterRedisClient::COMPONENT_PHPREDIS => [
     * 'max_pool_num' => 5,
     * 'max_push_timeout' => 2,
     * 'max_pop_timeout' => 1,
     * 'max_life_timeout' => 10,
     * 'enable_tick_clear_pool' => 0
     * ],
     */
    public const COMPONENT_PHPREDIS = '__websocket_php_redis';

    /** @var string 协程单例 Predis 组件别名，可在app.php 中配置连接池
     * ClusterRedisClient::COMPONENT_PREDIS => [
     * 'max_pool_num' => 5,
     * 'max_push_timeout' => 2,
     * 'max_pop_timeout' => 1,
     * 'max_life_timeout' => 10,
     * 'enable_tick_clear_pool' => 0
     * ]
    */
    public const COMPONENT_PREDIS = '__websocket_predis';

    /** @var ClusterRedisAdapterInterface|null 非协程 / 无 App 上下文降级连接 */
    private static ?ClusterRedisAdapterInterface $fallbackAdapter = null;

    /** @var array<int, ClusterRedisAdapterInterface> 无 EventApp 时按协程 cid 缓存的连接 */
    private static array $coroutineAdapters = [];

    /**
     * 在协程单例连接上执行 Redis 命令；连接异常时清组件并重试一次。
     */
    public static function execute(callable $callback)
    {
        try {
            return $callback(self::getAdapter());
        } catch (\Throwable $throwable) {
            self::resetSharedAdapter();

            return $callback(self::getAdapter());
        }
    }

    /** 关闭当前上下文 Redis 连接（单测 teardown 或重连前调用） */
    public static function resetSharedAdapter(): void
    {
        $app = Application::getApp();
        if ($app !== null && method_exists($app, 'clearComponent')) {
            foreach ([self::COMPONENT_PHPREDIS, self::COMPONENT_PREDIS] as $alias) {
                if (!method_exists($app, 'has') || !$app->has($alias)) {
                    continue;
                }

                $container = $app->getComponents($alias);
                if ($container instanceof ContainerObjectDto) {
                    $object = $container->getObject();
                    if ($object instanceof ClusterRedisAdapterInterface) {
                        $object->close();
                    }
                }

                $app->clearComponent($alias);
            }

            return;
        }

        self::closeCoroutineAdapter(
            class_exists(\Swoole\Coroutine::class) ? \Swoole\Coroutine::getCid() : 0
        );

        if (self::$fallbackAdapter !== null) {
            self::$fallbackAdapter->close();
            self::$fallbackAdapter = null;
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

    private static function getAdapter(): ClusterRedisAdapterInterface
    {
        $app = Application::getApp();
        if ($app !== null && method_exists($app, 'creatObject')) {
            return self::getAdapterFromApp($app);
        }

        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() > 0) {
            return self::getCoroutineAdapter();
        }

        if (self::$fallbackAdapter === null) {
            self::$fallbackAdapter = self::createAdapter();
        }

        return self::$fallbackAdapter;
    }

    /** 无 EventApp 时在协程内复用同一连接，defer 时 close 防止泄漏 */
    private static function getCoroutineAdapter(): ClusterRedisAdapterInterface
    {
        $cid = \Swoole\Coroutine::getCid();
        if (isset(self::$coroutineAdapters[$cid])) {
            return self::$coroutineAdapters[$cid];
        }

        $adapter = self::createAdapter();
        self::$coroutineAdapters[$cid] = $adapter;
        \Swoole\Coroutine::defer(static function () use ($cid): void {
            self::closeCoroutineAdapter($cid);
        });

        return $adapter;
    }

    private static function closeCoroutineAdapter(int $cid): void
    {
        if ($cid <= 0 || !isset(self::$coroutineAdapters[$cid])) {
            return;
        }

        self::$coroutineAdapters[$cid]->close();
        unset(self::$coroutineAdapters[$cid]);
    }

    /**
     * @param object $app EventController|App 等带 ComponentTrait 的协程应用实例
     */
    private static function getAdapterFromApp(object $app): ClusterRedisAdapterInterface
    {
        if (self::resolveDriver() === self::DRIVER_PHPREDIS) {
            $container = $app->creatObject(self::COMPONENT_PHPREDIS, static function (): PhpRedisClusterAdapter {
                return new PhpRedisClusterAdapter(self::connectPhpRedis());
            });
        } else {
            $container = $app->creatObject(self::COMPONENT_PREDIS, static function (): PredisClusterAdapter {
                return new PredisClusterAdapter(self::connectPredis());
            });
        }

        $object = $container instanceof ContainerObjectDto
            ? $container->getObject()
            : $container;

        if (!$object instanceof ClusterRedisAdapterInterface) {
            throw new ClusterRedisException('Invalid websocket redis component object');
        }

        return $object;
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
