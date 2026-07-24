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
 * ## 解析规则
 *
 * - 实例对象
 * - 类名字符串 / 单元素 `[Class::class]`（脚手架默认）
 * - `[Class, 'method']` 工厂方法，或 callable / 闭包（返回 Store 实例）
 *
 * MySQL 等持久化由**业务类**实现 {@see OfflineMessageStoreInterface}；框架只负责调度落库/补推。
 *
 * @see ClusterConfig::offlineSettings()
 * @see OfflineMessageCoordinator
 */
class OfflineMessageStoreFactory
{
    private static ?OfflineMessageStoreInterface $store = null;
    private static bool $resolved = false;
    private static ?OfflineMessageStoreInterface $override = null;

    /** 单测注入，优先级高于配置文件 */
    public static function setOverride(?OfflineMessageStoreInterface $store): void
    {
        self::$override = $store;
        self::$resolved = true;
        self::$store = $store;
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$resolved = false;
        self::$override = null;
    }

    /** 进程内单例；未配置或解析失败返回 null */
    public static function get(): ?OfflineMessageStoreInterface
    {
        if (self::$resolved) {
            return self::$store;
        }

        self::$resolved = true;
        if (self::$override instanceof OfflineMessageStoreInterface) {
            self::$store = self::$override;

            return self::$store;
        }

        $config = ClusterConfig::offlineSettings()['store'] ?? null;
        self::$store = self::resolve($config);

        return self::$store;
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

            // 工厂方法：[Factory::class, 'make'] → 返回 Store
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
}
