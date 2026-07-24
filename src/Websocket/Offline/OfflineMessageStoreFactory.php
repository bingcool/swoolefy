<?php

namespace Swoolefy\Websocket\Offline;

use Swoolefy\Websocket\Cluster\ClusterConfig;

/**
 * 从 Config/websocket.php → offline.store 加载离线消息存储。
 *
 * ## 与 {@see OfflineReconnectHookFactory} 的区别
 *
 * | | 本类（Store） | ReconnectHook |
 * |--|--|--|
 * | 配置键 | `offline.store` | `offline.on_reconnect` |
 * | 产出 | {@see OfflineMessageStoreInterface} | {@see OfflineReconnectHookInterface} |
 * | 职责 | **持久化**：离线消息的存 / 查 / ACK | **上线回调**：补推之后的业务逻辑 |
 * | 何时用 | 落库、补推、`pullPending`、`ackDelivered` | {@see OfflineMessageCoordinator::onUserOnline()} 内，补推之后 |
 * | 是否必须 | `enable=true` 时必须能解析，否则离线能力不算开启 | 可选；不配则只做框架补推 |
 *
 * 一句话：本类管「消息存在哪、怎么读写」；Hook 管「人上线了业务还要做什么」。
 *
 * ## 协程安全
 *
 * - **只缓存配置**，每次 {@see get()} 新建 Store 实例，避免业务在 `$this` 上缓存 user/request。
 * - Store 实现应无请求态：连接池/PDO 通过 Application 按需获取，或构造时注入无状态依赖。
 * - {@see setOverride()} 供单测注入并复用同一实例（如 InMemory 累加断言）。
 * - {@see isConfigured()} 不实例化，供 {@see OfflineMessageCoordinator::isEnabled()} 快速判断。
 *
 * ## 解析规则
 *
 * - 实例对象（配置里直接放对象则进程内共享，业务自负协程安全）
 * - 类名字符串 / 单元素 `[Class::class]`（脚手架默认）→ 每次 new
 * - `[Class, 'method']` 工厂方法，或 callable / 闭包 → 每次调用
 *
 * @see ClusterConfig::offlineSettings()
 * @see OfflineMessageCoordinator
 */
class OfflineMessageStoreFactory
{
    private static bool $configLoaded = false;

    /** @var mixed */
    private static $rawConfig = null;

    private static bool $hasOverride = false;

    private static ?OfflineMessageStoreInterface $override = null;

    /** 单测注入，优先级高于配置文件（复用同一实例） */
    public static function setOverride(?OfflineMessageStoreInterface $store): void
    {
        self::$hasOverride = true;
        self::$override = $store;
    }

    public static function reset(): void
    {
        self::$configLoaded = false;
        self::$rawConfig = null;
        self::$hasOverride = false;
        self::$override = null;
    }

    /**
     * 配置是否可解析为 Store（不 new 业务类）。
     */
    public static function isConfigured(): bool
    {
        if (self::$hasOverride) {
            return self::$override instanceof OfflineMessageStoreInterface;
        }

        self::loadConfigOnce();

        return self::configLooksValid(self::$rawConfig);
    }

    /**
     * 获取 Store：配置只读一次；默认每次新建实例。
     */
    public static function get(): ?OfflineMessageStoreInterface
    {
        if (self::$hasOverride) {
            return self::$override;
        }

        self::loadConfigOnce();

        return self::resolve(self::$rawConfig);
    }

    /**
     * 支持的配置形态（与 stub / README 一致）：
     * - OfflineMessageStoreInterface 实例
     * - 类名字符串：`MysqlOfflineMessageStore::class`
     * - 单元素数组：`[MysqlOfflineMessageStore::class]`（脚手架默认写法）
     * - 工厂：`[Factory::class, 'make']` 或 callable，返回 Store 实例
     *
     * @param mixed $config
     */
    private static function resolve($config): ?OfflineMessageStoreInterface
    {
        if ($config === null || $config === '') {
            return null;
        }

        if ($config instanceof OfflineMessageStoreInterface) {
            return $config;
        }

        // stub 写法：'store' => [App\Offline\MysqlOfflineMessageStore::class]
        if (is_array($config)) {
            $items = array_values($config);
            if (count($items) === 1 && is_string($items[0]) && class_exists($items[0])) {
                return self::instantiateStore($items[0]);
            }

            // 工厂方法：[Factory::class, 'make'] → 每次调用 make，由工厂决定是否返回新实例
            if (
                count($items) === 2
                && is_string($items[0])
                && is_string($items[1])
                && class_exists($items[0])
            ) {
                $factory = new $items[0]();
                if (method_exists($factory, $items[1])) {
                    $result = $factory->{$items[1]}();

                    return $result instanceof OfflineMessageStoreInterface ? $result : null;
                }
            }
        }

        if (is_string($config) && class_exists($config)) {
            return self::instantiateStore($config);
        }

        // 闭包 / 可调用工厂：fn () => new MysqlOfflineMessageStore(...)
        if (is_callable($config)) {
            $instance = $config();

            return $instance instanceof OfflineMessageStoreInterface ? $instance : null;
        }

        return null;
    }

    private static function instantiateStore(string $class): ?OfflineMessageStoreInterface
    {
        $instance = new $class();

        return $instance instanceof OfflineMessageStoreInterface ? $instance : null;
    }

    /**
     * @param mixed $config
     */
    private static function configLooksValid($config): bool
    {
        if ($config === null || $config === '') {
            return false;
        }

        if ($config instanceof OfflineMessageStoreInterface) {
            return true;
        }

        if (is_array($config)) {
            $items = array_values($config);
            if (count($items) === 1 && is_string($items[0])) {
                return class_exists($items[0]) && is_a($items[0], OfflineMessageStoreInterface::class, true);
            }
            if (
                count($items) === 2
                && is_string($items[0])
                && is_string($items[1])
                && class_exists($items[0])
            ) {
                return method_exists($items[0], $items[1]) || (new \ReflectionClass($items[0]))->hasMethod($items[1]);
            }

            return false;
        }

        if (is_string($config)) {
            return class_exists($config) && is_a($config, OfflineMessageStoreInterface::class, true);
        }

        return is_callable($config);
    }

    private static function loadConfigOnce(): void
    {
        if (self::$configLoaded) {
            return;
        }

        self::$configLoaded = true;
        self::$rawConfig = ClusterConfig::offlineSettings()['store'] ?? null;
    }
}
